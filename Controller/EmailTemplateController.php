<?php

declare(strict_types=1);

namespace MyFlyingBox\Controller;

use MyFlyingBox\Form\EmailTemplateForm;
use MyFlyingBox\Service\EmailRenderingService;
use MyFlyingBox\Service\EmailTemplateService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Thelia\Controller\Admin\BaseAdminController;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;

class EmailTemplateController extends BaseAdminController
{
    /**
     * Display list of email templates
     */
    public function listAction(EmailTemplateService $templateService): Response
    {
        if (null !== $response = $this->checkAuth(AdminResources::MODULE, 'MyFlyingBox', AccessManager::VIEW)) {
            return $response;
        }

        return $this->render('email-templates-list', [
            'module_code' => 'MyFlyingBox',
            'available_variables' => $templateService->getAvailableVariables(),
            'available_codes' => $templateService->getAvailableCodes(),
        ]);
    }

    /**
     * Display create template form
     */
    public function createAction(EmailTemplateService $templateService): Response
    {
        if (null !== $response = $this->checkAuth(AdminResources::MODULE, 'MyFlyingBox', AccessManager::CREATE)) {
            return $response;
        }

        return $this->render('email-template-edit', [
            'module_code' => 'MyFlyingBox',
            'template_id' => null,
            'available_variables' => $templateService->getAvailableVariables(),
            'available_codes' => $templateService->getAvailableCodes(),
        ]);
    }

    /**
     * Display edit template form
     */
    public function editAction(int $id, EmailTemplateService $templateService): Response
    {
        if (null !== $response = $this->checkAuth(AdminResources::MODULE, 'MyFlyingBox', AccessManager::UPDATE)) {
            return $response;
        }

        $template = $templateService->getTemplateById($id);

        if ($template === null) {
            return new RedirectResponse('/admin/module/myflyingbox/email-templates');
        }

        return $this->render('email-template-edit', [
            'module_code' => 'MyFlyingBox',
            'template_id' => $id,
            'template' => $template,
            'available_variables' => $templateService->getAvailableVariables(),
            'available_codes' => $templateService->getAvailableCodes(),
        ]);
    }

    /**
     * Save template (create or update)
     */
    public function saveAction(Request $request, EmailTemplateService $templateService): Response
    {
        if (null !== $response = $this->checkAuth(AdminResources::MODULE, 'MyFlyingBox', AccessManager::UPDATE)) {
            return $response;
        }

        $form = $this->createForm(EmailTemplateForm::getName());

        try {
            $data = $this->validateForm($form)->getData();

            $id = !empty($data['id']) ? (int) $data['id'] : null;

            $templateService->saveTemplate(
                $id,
                $data['code'],
                $data['locale'],
                $data['name'],
                $data['subject'],
                $data['html_content'],
                $data['text_content'] ?? null,
                (bool) ($data['is_active'] ?? true)
            );

            return new RedirectResponse('/admin/module/myflyingbox/email-templates');

        } catch (\Exception $e) {
            $this->setupFormErrorContext(
                'Email Template',
                $e->getMessage(),
                $form
            );

            $id = $request->request->get('id');
            $redirectUrl = $id
                ? "/admin/module/myflyingbox/email-template/{$id}"
                : '/admin/module/myflyingbox/email-template/create';

            return new RedirectResponse($redirectUrl);
        }
    }

    /**
     * Delete template
     */
    public function deleteAction(Request $request, EmailTemplateService $templateService): JsonResponse
    {
        if (null !== $this->checkAuth(AdminResources::MODULE, 'MyFlyingBox', AccessManager::DELETE)) {
            return new JsonResponse(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        try {
            $data = json_decode($request->getContent(), true);
            $id = (int) ($data['id'] ?? 0);

            if ($id === 0) {
                return new JsonResponse(['success' => false, 'message' => 'ID required']);
            }

            $result = $templateService->deleteTemplate($id);

            return new JsonResponse([
                'success' => $result,
                'message' => $result ? 'Template deleted' : 'Template not found',
            ]);

        } catch (\InvalidArgumentException $e) {
            return new JsonResponse([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Preview template with sample data (AJAX)
     */
    public function previewAction(Request $request, EmailRenderingService $renderingService): JsonResponse
    {
        if (null !== $this->checkAuth(AdminResources::MODULE, 'MyFlyingBox', AccessManager::VIEW)) {
            return new JsonResponse(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        try {
            $data = json_decode($request->getContent(), true);

            $subject = $data['subject'] ?? '';
            $htmlContent = $data['html_content'] ?? '';
            $textContent = $data['text_content'] ?? null;

            if (empty($subject) || empty($htmlContent)) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'Subject and HTML content are required',
                ]);
            }

            $rendered = $renderingService->previewWithCustomContent(
                $subject,
                $htmlContent,
                $textContent
            );

            return new JsonResponse([
                'success' => true,
                'subject' => $rendered['subject'],
                'html' => $rendered['html'],
                'text' => $rendered['text'],
            ]);

        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Reset template to default content
     */
    public function resetAction(Request $request, EmailTemplateService $templateService): JsonResponse
    {
        if (null !== $this->checkAuth(AdminResources::MODULE, 'MyFlyingBox', AccessManager::UPDATE)) {
            return new JsonResponse(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        try {
            $data = json_decode($request->getContent(), true);
            $id = (int) ($data['id'] ?? 0);

            if ($id === 0) {
                return new JsonResponse(['success' => false, 'message' => 'ID required']);
            }

            $template = $templateService->resetToDefault($id);

            return new JsonResponse([
                'success' => true,
                'message' => 'Template reset to default',
                'template' => [
                    'id' => $template->getId(),
                    'name' => $template->getName(),
                    'subject' => $template->getSubject(),
                ],
            ]);

        } catch (\InvalidArgumentException $e) {
            return new JsonResponse([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Duplicate template for another locale
     */
    public function duplicateAction(int $id, Request $request, EmailTemplateService $templateService): JsonResponse
    {
        if (null !== $this->checkAuth(AdminResources::MODULE, 'MyFlyingBox', AccessManager::CREATE)) {
            return new JsonResponse(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        try {
            $data = json_decode($request->getContent(), true);
            $targetLocale = $data['locale'] ?? '';

            if (empty($targetLocale)) {
                return new JsonResponse(['success' => false, 'message' => 'Target locale required']);
            }

            $source = $templateService->getTemplateById($id);

            if ($source === null) {
                return new JsonResponse(['success' => false, 'message' => 'Source template not found']);
            }

            $newTemplate = $templateService->saveTemplate(
                null,
                $source->getCode(),
                $targetLocale,
                $source->getName() . ' (' . $targetLocale . ')',
                $source->getSubject(),
                $source->getHtmlContent(),
                $source->getTextContent(),
                $source->getIsActive()
            );

            return new JsonResponse([
                'success' => true,
                'message' => 'Template duplicated',
                'template_id' => $newTemplate->getId(),
            ]);

        } catch (\InvalidArgumentException $e) {
            return new JsonResponse([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Seed default templates (admin action)
     */
    public function seedDefaultsAction(EmailTemplateService $templateService): JsonResponse
    {
        if (null !== $this->checkAuth(AdminResources::MODULE, 'MyFlyingBox', AccessManager::UPDATE)) {
            return new JsonResponse(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        try {
            $templateService->seedDefaultTemplates();

            return new JsonResponse([
                'success' => true,
                'message' => 'Default templates seeded',
            ]);

        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
