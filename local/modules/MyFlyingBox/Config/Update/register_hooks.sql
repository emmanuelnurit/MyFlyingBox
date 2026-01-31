-- Register module.configuration hook for MyFlyingBox
-- This enables the "Configure" button in the modules list

-- Get the module ID and hook ID, then insert if not exists
INSERT INTO module_hook (module_id, hook_id, classname, method, active, hook_active, module_active, position)
SELECT
    m.id as module_id,
    h.id as hook_id,
    'MyFlyingBox\\Hook\\BackHook' as classname,
    'onModuleConfiguration' as method,
    1 as active,
    1 as hook_active,
    1 as module_active,
    1 as position
FROM module m, hook h
WHERE m.code = 'MyFlyingBox'
AND h.code = 'module.configuration'
AND h.type = 2
AND NOT EXISTS (
    SELECT 1 FROM module_hook mh
    WHERE mh.module_id = m.id AND mh.hook_id = h.id
);

-- Register module.config-js hook
INSERT INTO module_hook (module_id, hook_id, classname, method, active, hook_active, module_active, position)
SELECT
    m.id as module_id,
    h.id as hook_id,
    'MyFlyingBox\\Hook\\BackHook' as classname,
    'onModuleConfigJs' as method,
    1 as active,
    1 as hook_active,
    1 as module_active,
    1 as position
FROM module m, hook h
WHERE m.code = 'MyFlyingBox'
AND h.code = 'module.config-js'
AND h.type = 2
AND NOT EXISTS (
    SELECT 1 FROM module_hook mh
    WHERE mh.module_id = m.id AND mh.hook_id = h.id
);
