<?php
declare(strict_types=1);

require_once __DIR__ . '/session_init.php';
require_once __DIR__ . '/get_config.php';
require_once __DIR__ . '/db.php';

require_once __DIR__ . '/inc/login-session.inc.php';
require_once __DIR__ . '/inc/utils.inc.php';
require_once __DIR__ . '/inc/rag_paths.php';
require_once __DIR__ . '/inc/system-message.inc.php';
require_once __DIR__ . '/inc/workflows.inc.php';
require_once __DIR__ . '/inc/images.inc.php';
require_once __DIR__ . '/inc/mocha.inc.php';
require_once __DIR__ . '/inc/RAG.inc.php';
require_once __DIR__ . '/inc/assistants.inc.php';
require_once __DIR__ . '/inc/azure-api.inc.php';
require_once __DIR__ . '/inc/chat_title_service.php';
require_once __DIR__ . '/inc/app_bootstrap.php';

$pdo = get_connection();

if (!defined('DOC_GEN_DIR')) {
    define('DOC_GEN_DIR', dirname(__DIR__) . '/doc_gen');
}

$appBootstrap = new AppBootstrap($config);
$appContext   = $appBootstrap->initialize();

extract($appContext, EXTR_OVERWRITE);
