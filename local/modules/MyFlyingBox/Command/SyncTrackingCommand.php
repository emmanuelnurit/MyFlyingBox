<?php

namespace MyFlyingBox\Command;

use MyFlyingBox\Model\MyFlyingBoxShipmentQuery;
use MyFlyingBox\Service\ShipmentService;
use MyFlyingBox\Service\TrackingService;
use Propel\Runtime\ActiveQuery\Criteria;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Thelia\Command\ContainerAwareCommand;

/**
 * CLI command to synchronize tracking information from LCE API
 *
 * Usage:
 *   php Thelia myflyingbox:sync-tracking
 *   php Thelia myflyingbox:sync-tracking --order-id=123
 *   php Thelia myflyingbox:sync-tracking --days=30 --status=shipped
 *   php Thelia myflyingbox:sync-tracking --dry-run
 */
class SyncTrackingCommand extends ContainerAwareCommand
{
    protected TrackingService $trackingService;
    protected LoggerInterface $logger;

    public function __construct(TrackingService $trackingService, LoggerInterface $logger)
    {
        parent::__construct();
        $this->trackingService = $trackingService;
        $this->logger = $logger;
    }

    protected function configure(): void
    {
        $this
            ->setName('myflyingbox:sync-tracking')
            ->setDescription('Synchronize tracking information from MyFlyingBox/LCE API')
            ->addOption(
                'order-id',
                null,
                InputOption::VALUE_REQUIRED,
                'Synchronize tracking for a specific order ID'
            )
            ->addOption(
                'days',
                'd',
                InputOption::VALUE_REQUIRED,
                'Synchronize shipments from the last N days',
                7
            )
            ->addOption(
                'status',
                's',
                InputOption::VALUE_REQUIRED,
                'Filter by shipment status (pending, booked, shipped)'
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Show what would be synchronized without making changes'
            )
            ->setHelp(<<<'HELP'
The <info>%command.name%</info> command synchronizes tracking information from the MyFlyingBox/LCE API.

<info>Synchronize all shipments from the last 7 days:</info>
  <comment>php Thelia %command.name%</comment>

<info>Synchronize a specific order:</info>
  <comment>php Thelia %command.name% --order-id=123</comment>

<info>Synchronize shipped orders from the last 30 days:</info>
  <comment>php Thelia %command.name% --days=30 --status=shipped</comment>

<info>Preview what would be synchronized:</info>
  <comment>php Thelia %command.name% --dry-run</comment>

Available statuses: pending, booked, shipped
HELP
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->initRequest();

        $orderId = $input->getOption('order-id');
        $days = (int) $input->getOption('days');
        $status = $input->getOption('status');
        $dryRun = $input->getOption('dry-run');

        // Validate status option
        $validStatuses = [
            ShipmentService::STATUS_PENDING,
            ShipmentService::STATUS_BOOKED,
            ShipmentService::STATUS_SHIPPED,
        ];

        if ($status && !in_array($status, $validStatuses)) {
            $output->writeln(sprintf(
                '<error>Invalid status "%s". Valid statuses are: %s</error>',
                $status,
                implode(', ', $validStatuses)
            ));
            return 1;
        }

        // Build query
        $query = MyFlyingBoxShipmentQuery::create()
            ->filterByApiOrderUuid(null, Criteria::ISNOTNULL)
            ->filterByApiOrderUuid('', Criteria::NOT_EQUAL);

        // Filter by order ID if provided
        if ($orderId) {
            $query->filterByOrderId((int) $orderId);
            $output->writeln(sprintf('<info>Filtering by order ID: %d</info>', $orderId));
        } else {
            // Filter by date range
            $fromDate = new \DateTime("-{$days} days");
            $query->filterByCreatedAt($fromDate, Criteria::GREATER_EQUAL);
            $output->writeln(sprintf('<info>Filtering shipments from the last %d days</info>', $days));
        }

        // Filter by status
        if ($status) {
            $query->filterByStatus($status);
            $output->writeln(sprintf('<info>Filtering by status: %s</info>', $status));
        } else {
            // Only sync non-final statuses by default
            $query->filterByStatus([
                ShipmentService::STATUS_PENDING,
                ShipmentService::STATUS_BOOKED,
                ShipmentService::STATUS_SHIPPED,
            ], Criteria::IN);
        }

        // Order by creation date
        $query->orderByCreatedAt(Criteria::DESC);

        $shipments = $query->find();
        $totalShipments = $shipments->count();

        if ($totalShipments === 0) {
            $output->writeln('<comment>No shipments found matching the criteria.</comment>');
            return 0;
        }

        $output->writeln(sprintf('<info>Found %d shipment(s) to synchronize</info>', $totalShipments));
        $output->writeln('');

        if ($dryRun) {
            $output->writeln('<comment>DRY RUN MODE - No changes will be made</comment>');
            $output->writeln('');

            // Display shipments that would be processed
            $table = new Table($output);
            $table->setHeaders(['Shipment ID', 'Order ID', 'Status', 'API Order UUID', 'Created At']);

            foreach ($shipments as $shipment) {
                $table->addRow([
                    $shipment->getId(),
                    $shipment->getOrderId(),
                    $shipment->getStatus(),
                    substr($shipment->getApiOrderUuid(), 0, 16) . '...',
                    $shipment->getCreatedAt()?->format('Y-m-d H:i'),
                ]);
            }

            $table->render();
            return 0;
        }

        // Progress bar
        $progressBar = new ProgressBar($output, $totalShipments);
        $progressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% %elapsed:6s% - %message%');
        $progressBar->setMessage('Starting...');
        $progressBar->start();

        // Statistics
        $stats = [
            'total' => $totalShipments,
            'success' => 0,
            'updated' => 0,
            'no_changes' => 0,
            'errors' => 0,
            'error_details' => [],
        ];

        foreach ($shipments as $shipment) {
            $progressBar->setMessage(sprintf('Order #%d', $shipment->getOrderId()));

            try {
                $previousStatus = $shipment->getStatus();

                // Sync tracking from API
                $result = $this->trackingService->syncTrackingStatus($shipment->getId());

                // Reload shipment to get updated status
                $shipment->reload();
                $newStatus = $shipment->getStatus();

                $stats['success']++;

                if ($result || $previousStatus !== $newStatus) {
                    $stats['updated']++;
                    $this->logger->info('Tracking synchronized', [
                        'shipment_id' => $shipment->getId(),
                        'order_id' => $shipment->getOrderId(),
                        'previous_status' => $previousStatus,
                        'new_status' => $newStatus,
                    ]);
                } else {
                    $stats['no_changes']++;
                }
            } catch (\Exception $e) {
                $stats['errors']++;
                $stats['error_details'][] = [
                    'shipment_id' => $shipment->getId(),
                    'order_id' => $shipment->getOrderId(),
                    'error' => $e->getMessage(),
                ];

                $this->logger->error('Failed to sync tracking', [
                    'shipment_id' => $shipment->getId(),
                    'order_id' => $shipment->getOrderId(),
                    'error' => $e->getMessage(),
                ]);
            }

            $progressBar->advance();
        }

        $progressBar->setMessage('Completed');
        $progressBar->finish();
        $output->writeln('');
        $output->writeln('');

        // Display summary
        $this->displaySummary($output, $stats);

        // Display errors if any
        if (!empty($stats['error_details'])) {
            $this->displayErrors($output, $stats['error_details']);
        }

        return $stats['errors'] > 0 ? 1 : 0;
    }

    /**
     * Display synchronization summary
     */
    private function displaySummary(OutputInterface $output, array $stats): void
    {
        $output->writeln('<info>========== Synchronization Summary ==========</info>');
        $output->writeln('');

        $table = new Table($output);
        $table->setStyle('compact');
        $table->setRows([
            ['Total shipments processed:', sprintf('<comment>%d</comment>', $stats['total'])],
            ['Successfully synchronized:', sprintf('<info>%d</info>', $stats['success'])],
            ['Updated with new data:', sprintf('<info>%d</info>', $stats['updated'])],
            ['No changes:', sprintf('<comment>%d</comment>', $stats['no_changes'])],
            ['Errors:', $stats['errors'] > 0 ? sprintf('<error>%d</error>', $stats['errors']) : '0'],
        ]);
        $table->render();

        $output->writeln('');
    }

    /**
     * Display error details
     */
    private function displayErrors(OutputInterface $output, array $errors): void
    {
        $output->writeln('<error>========== Errors ==========</error>');
        $output->writeln('');

        $table = new Table($output);
        $table->setHeaders(['Shipment ID', 'Order ID', 'Error']);

        foreach ($errors as $error) {
            $table->addRow([
                $error['shipment_id'],
                $error['order_id'],
                substr($error['error'], 0, 60) . (strlen($error['error']) > 60 ? '...' : ''),
            ]);
        }

        $table->render();
        $output->writeln('');
    }
}
