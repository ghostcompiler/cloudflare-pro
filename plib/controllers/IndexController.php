<?php

require_once pm_Context::getPlibDir() . 'library/TokenRepository.php';
require_once pm_Context::getPlibDir() . 'library/ApiLogRepository.php';
require_once pm_Context::getPlibDir() . 'library/SettingsRepository.php';
require_once pm_Context::getPlibDir() . 'library/DomainRepository.php';
require_once pm_Context::getPlibDir() . 'library/SyncJobRepository.php';
require_once pm_Context::getPlibDir() . 'library/CloudflarePro/Permissions.php';
require_once pm_Context::getPlibDir() . 'library/CloudflarePro/CloudflareClient.php';
require_once pm_Context::getPlibDir() . 'library/CloudflarePro/PleskDnsService.php';
require_once pm_Context::getPlibDir() . 'library/CloudflarePro/DomainSyncService.php';

class IndexController extends pm_Controller_Action
{
    public function init()
    {
        parent::init();

        CloudflarePro_Permissions::assertAccess();
        $this->view->tabs = $this->getTabs($this->_request->getActionName());
    }

    public function indexAction()
    {
        $this->_forward('domains');
    }

    public function domainsAction()
    {
        $service = new CloudflarePro_DomainSyncService();
        $this->view->domains = $service->linkedDomains();
        $this->view->syncDomainAction = pm_Context::getActionUrl('index', 'sync-domain');
        $this->view->startSyncJobAction = pm_Context::getActionUrl('index', 'start-sync-job');
        $this->view->processSyncJobAction = pm_Context::getActionUrl('index', 'process-sync-job');
        $this->view->syncJobStatusAction = pm_Context::getActionUrl('index', 'sync-job-status');
        $this->view->toggleAutosyncAction = pm_Context::getActionUrl('index', 'toggle-autosync');
        $this->view->recordsAction = pm_Context::getActionUrl('index', 'records');

        $this->renderTab(
            'Domains',
            'No linked Cloudflare domains yet',
            'Add a valid token with access to zones matching your Plesk domains.',
            'domains'
        );
    }

    public function syncDomainAction()
    {
        $this->disableRendering();

        if (!$this->_request->isPost()) {
            return $this->jsonResponse(false, 'Invalid request method.');
        }

        $linkId = (int) $this->_request->getPost('link_id', 0);
        if ($linkId <= 0) {
            return $this->jsonResponse(false, 'Linked domain is required.');
        }

        try {
            $service = new CloudflarePro_DomainSyncService();
            $result = $service->syncLink($linkId);

            return $this->jsonResponse(true, 'Domain synced to Cloudflare successfully.', [
                'domains' => $service->linkedDomains(),
                'result' => $result,
            ]);
        } catch (Throwable $e) {
            try {
                $service = isset($service) ? $service : new CloudflarePro_DomainSyncService();
                $domains = $service->linkedDomains();
            } catch (Throwable $ignored) {
                $domains = [];
            }

            return $this->jsonResponse(false, $e->getMessage(), [
                'domains' => $domains,
            ]);
        }
    }

    public function toggleAutosyncAction()
    {
        $this->disableRendering();

        if (!$this->_request->isPost()) {
            return $this->jsonResponse(false, 'Invalid request method.');
        }

        $linkId = (int) $this->_request->getPost('link_id', 0);
        if ($linkId <= 0) {
            return $this->jsonResponse(false, 'Linked domain is required.');
        }

        try {
            $repository = new Modules_CloudflarePro_DomainRepository();
            $repository->setAutoSync($linkId, $this->truthyPost('auto_sync'));
            $service = new CloudflarePro_DomainSyncService();

            return $this->jsonResponse(true, 'Auto Sync updated successfully.', [
                'domains' => $service->linkedDomains(),
            ]);
        } catch (Throwable $e) {
            return $this->jsonResponse(false, $e->getMessage());
        }
    }

    public function recordsAction()
    {
        $linkId = (int) $this->_request->getParam('link_id', 0);
        if ($linkId <= 0) {
            throw new pm_Exception('Linked domain is required.');
        }

        $service = new CloudflarePro_DomainSyncService();
        $data = $service->recordsForLink($linkId);

        $this->view->recordDomain = $data['domain'];
        $this->view->records = $data['records'];
        $this->view->setRecordProxyAction = pm_Context::getActionUrl('index', 'set-record-proxy');
        $this->view->syncDomainAction = pm_Context::getActionUrl('index', 'sync-domain');
        $this->view->startSyncJobAction = pm_Context::getActionUrl('index', 'start-sync-job');
        $this->view->processSyncJobAction = pm_Context::getActionUrl('index', 'process-sync-job');
        $this->view->syncJobStatusAction = pm_Context::getActionUrl('index', 'sync-job-status');
        $this->view->recordAction = pm_Context::getActionUrl('index', 'record-action');
        $this->renderTab(
            'Domain: ' . $data['domain']['domain_name'],
            'No DNS records found',
            'DNS records will appear here after they are available in Plesk or Cloudflare.',
            'records'
        );
    }

    public function startSyncJobAction()
    {
        $this->disableRendering();

        if (!$this->_request->isPost()) {
            return $this->jsonResponse(false, 'Invalid request method.');
        }

        $linkId = (int) $this->_request->getPost('link_id', 0);
        if ($linkId <= 0) {
            return $this->jsonResponse(false, 'Linked domain is required.');
        }

        try {
            $service = new CloudflarePro_DomainSyncService();
            $repository = new Modules_CloudflarePro_SyncJobRepository();
            $mode = trim((string) $this->_request->getPost('mode', 'export'));
            if (!in_array($mode, ['import', 'export', 'sync'], true)) {
                $mode = 'export';
            }
            $items = 'import' === $mode ? $service->queueImportItemsForLink($linkId) : $service->queueItemsForLink($linkId);
            $job = $repository->create($linkId, $items, $mode);

            return $this->jsonResponse(true, 'Sync job started.', $repository->response($job));
        } catch (Throwable $e) {
            return $this->jsonResponse(false, $e->getMessage());
        }
    }

    public function processSyncJobAction()
    {
        $this->disableRendering();

        if (!$this->_request->isPost()) {
            return $this->jsonResponse(false, 'Invalid request method.');
        }

        $jobId = (int) $this->_request->getPost('job_id', 0);
        if ($jobId <= 0) {
            return $this->jsonResponse(false, 'Sync job is required.');
        }

        try {
            $repository = new Modules_CloudflarePro_SyncJobRepository();
            $service = new CloudflarePro_DomainSyncService();
            $job = $repository->find($jobId);

            if ('done' === $job['status']) {
                $data = $service->recordsForLink($job['link_id']);

                return $this->jsonResponse(true, 'Sync job completed.', array_merge($repository->response($job), [
                    'records' => $data['records'],
                    'domains' => $service->linkedDomains(),
                ]));
            }

            $batchSize = 3;
            $items = array_slice($job['items'], $job['processed'], $batchSize);
            $processed = $job['processed'];
            $created = $job['created'];
            $updated = $job['updated'];
            $failed = $job['failed'];
            $error = null;

            foreach ($items as $item) {
                try {
                    $result = 'import' === $job['mode']
                        ? $service->processImportQueueBatch($job['link_id'], [$item])
                        : $service->processSyncQueueBatch($job['link_id'], [$item]);
                    $created += (int) $result['created'];
                    $updated += (int) $result['updated'];
                } catch (Throwable $e) {
                    $failed++;
                    $error = $e->getMessage();
                }
                $processed++;
            }

            $status = $processed >= $job['total'] ? 'done' : 'running';
            $job = $repository->markProgress($job['id'], $processed, $created, $updated, $failed, $status, $error);

            $response = $repository->response($job);
            if ('done' === $status) {
                $data = $service->recordsForLink($job['link_id']);
                $response['records'] = $data['records'];
                $response['domains'] = $service->linkedDomains();
            }

            return $this->jsonResponse(true, 'Sync job updated.', $response);
        } catch (Throwable $e) {
            return $this->jsonResponse(false, $e->getMessage());
        }
    }

    public function syncJobStatusAction()
    {
        $this->disableRendering();

        if (!$this->_request->isPost()) {
            return $this->jsonResponse(false, 'Invalid request method.');
        }

        $linkId = (int) $this->_request->getPost('link_id', 0);
        if ($linkId <= 0) {
            return $this->jsonResponse(false, 'Linked domain is required.');
        }

        try {
            $repository = new Modules_CloudflarePro_SyncJobRepository();
            $job = $repository->findRunningByLink($linkId);

            return $this->jsonResponse(true, 'Sync job status loaded.', $job ? $repository->response($job) : [
                'job' => null,
            ]);
        } catch (Throwable $e) {
            return $this->jsonResponse(false, $e->getMessage());
        }
    }

    public function setRecordProxyAction()
    {
        $this->disableRendering();

        if (!$this->_request->isPost()) {
            return $this->jsonResponse(false, 'Invalid request method.');
        }

        $linkId = (int) $this->_request->getPost('link_id', 0);
        $recordId = trim((string) $this->_request->getPost('record_id', ''));
        if ($linkId <= 0 || '' === $recordId) {
            return $this->jsonResponse(false, 'Linked domain and DNS record are required.');
        }

        try {
            $service = new CloudflarePro_DomainSyncService();
            $data = $service->setRecordProxy($linkId, $recordId, $this->truthyPost('proxied'));

            return $this->jsonResponse(true, 'Proxy updated successfully.', [
                'records' => $data['records'],
            ]);
        } catch (Throwable $e) {
            return $this->jsonResponse(false, $e->getMessage());
        }
    }

    public function recordActionAction()
    {
        $this->disableRendering();

        if (!$this->_request->isPost()) {
            return $this->jsonResponse(false, 'Invalid request method.');
        }

        $linkId = (int) $this->_request->getPost('link_id', 0);
        $direction = trim((string) $this->_request->getPost('direction', ''));
        $recordKey = trim((string) $this->_request->getPost('record_key', ''));

        if ($linkId <= 0 || '' === $recordKey) {
            return $this->jsonResponse(false, 'Linked domain and DNS record are required.');
        }

        try {
            $service = new CloudflarePro_DomainSyncService();
            if ('refresh' === $direction) {
                $data = $service->recordsForLink($linkId);
                $message = 'Records refreshed successfully.';
            } elseif ('pull' === $direction) {
                $data = $service->pullRecord($linkId, $recordKey);
                $message = 'Record pulled to Plesk successfully.';
            } elseif ('delete' === $direction) {
                $data = $service->deleteRecord($linkId, $recordKey);
                $message = 'Record deleted from Plesk and Cloudflare successfully.';
            } else {
                $data = $service->pushRecord($linkId, $recordKey);
                $message = 'Record pushed to Cloudflare successfully.';
            }

            return $this->jsonResponse(true, $message, [
                'records' => $data['records'],
            ]);
        } catch (Throwable $e) {
            return $this->jsonResponse(false, $e->getMessage());
        }
    }

    public function tokensAction()
    {
        $repository = new Modules_CloudflarePro_TokenRepository();

        $this->view->tokens = $repository->all();
        $this->view->addTokenAction = pm_Context::getActionUrl('index', 'add-token');
        $this->view->updateTokenAction = pm_Context::getActionUrl('index', 'update-token');
        $this->view->validateTokenAction = pm_Context::getActionUrl('index', 'validate-token');
        $this->view->deleteTokenAction = pm_Context::getActionUrl('index', 'delete-token');
        $this->renderTab(
            'Tokens',
            'No tokens added yet',
            'Use Add Token to connect a Cloudflare API token.',
            'tokens'
        );
    }

    public function addTokenAction()
    {
        $this->disableRendering();

        if (!$this->_request->isPost()) {
            return $this->jsonResponse(false, 'Invalid request method.');
        }

        $name = trim($this->_request->getPost('name', ''));
        $token = trim($this->_request->getPost('token', ''));

        if ('' === $name) {
            return $this->jsonResponse(false, 'Token name is required.');
        }

        if ('' === $token) {
            return $this->jsonResponse(false, 'API token is required.');
        }

        try {
            $cloudflare = new CloudflarePro_CloudflareClient();
            $token = $cloudflare->assertToken($token);
            $verification = $cloudflare->verifyToken($token);

            $repository = new Modules_CloudflarePro_TokenRepository();
            $repository->add($name, $token, $verification);

            return $this->jsonResponse(true, 'Token added successfully.', array(
                'tokens' => $repository->all(),
            ));
        } catch (Throwable $e) {
            return $this->jsonResponse(false, $e->getMessage());
        }
    }

    public function updateTokenAction()
    {
        $this->disableRendering();

        if (!$this->_request->isPost()) {
            return $this->jsonResponse(false, 'Invalid request method.');
        }

        $id = (int) $this->_request->getPost('id', 0);
        $name = trim($this->_request->getPost('name', ''));
        $token = trim($this->_request->getPost('token', ''));

        if ($id <= 0) {
            return $this->jsonResponse(false, 'Token is required.');
        }

        if ('' === $name) {
            return $this->jsonResponse(false, 'Token name is required.');
        }

        try {
            $repository = new Modules_CloudflarePro_TokenRepository();
            if ('' !== $token) {
                $cloudflare = new CloudflarePro_CloudflareClient();
                $token = $cloudflare->assertToken($token);
                $verification = $cloudflare->verifyToken($token);
                $repository->updateToken($id, $name, $token, $verification);
            } else {
                $repository->updateName($id, $name);
            }

            return $this->jsonResponse(true, 'Token updated successfully.', array(
                'tokens' => $repository->all(),
            ));
        } catch (Throwable $e) {
            return $this->jsonResponse(false, $e->getMessage());
        }
    }

    public function validateTokenAction()
    {
        $this->disableRendering();

        if (!$this->_request->isPost()) {
            return $this->jsonResponse(false, 'Invalid request method.');
        }

        $id = (int) $this->_request->getPost('id', 0);
        if ($id <= 0) {
            return $this->jsonResponse(false, 'Token is required.');
        }

        try {
            $repository = new Modules_CloudflarePro_TokenRepository();
            $cloudflare = new CloudflarePro_CloudflareClient();
            $verification = $cloudflare->verifyToken($repository->secret($id));
            $repository->markValidated($id, $verification);

            return $this->jsonResponse(true, 'Token validated successfully.', array(
                'tokens' => $repository->all(),
            ));
        } catch (Throwable $e) {
            try {
                $repository = isset($repository) ? $repository : new Modules_CloudflarePro_TokenRepository();
                if ($id > 0) {
                    $repository->markInvalid($id);
                }
            } catch (Throwable $ignored) {
            }

            return $this->jsonResponse(false, $e->getMessage(), array(
                'tokens' => isset($repository) ? $repository->all() : array(),
            ));
        }
    }

    public function deleteTokenAction()
    {
        $this->disableRendering();

        if (!$this->_request->isPost()) {
            return $this->jsonResponse(false, 'Invalid request method.');
        }

        $id = (int) $this->_request->getPost('id', 0);
        if ($id <= 0) {
            return $this->jsonResponse(false, 'Token is required.');
        }

        try {
            $repository = new Modules_CloudflarePro_TokenRepository();
            $repository->delete($id);
            $domains = new Modules_CloudflarePro_DomainRepository();
            $domains->removeByToken($id);

            return $this->jsonResponse(true, 'Token deleted successfully.', array(
                'tokens' => $repository->all(),
            ));
        } catch (Throwable $e) {
            return $this->jsonResponse(false, $e->getMessage());
        }
    }

    public function logsAction()
    {
        $repository = new Modules_CloudflarePro_ApiLogRepository();

        $this->view->apiLogs = $repository->all();
        $this->view->clearLogsAction = pm_Context::getActionUrl('index', 'clear-logs');
        $this->renderTab(
            'API Logs',
            'No API calls logged yet',
            'Cloudflare API calls will appear here after token validation or sync actions.',
            'logs'
        );
    }

    public function clearLogsAction()
    {
        $this->disableRendering();

        if (!$this->_request->isPost()) {
            return $this->jsonResponse(false, 'Invalid request method.');
        }

        try {
            $repository = new Modules_CloudflarePro_ApiLogRepository();
            $repository->clear();

            return $this->jsonResponse(true, 'API logs removed successfully.', [
                'apiLogs' => [],
            ]);
        } catch (Throwable $e) {
            return $this->jsonResponse(false, $e->getMessage());
        }
    }

    public function settingsAction()
    {
        $repository = new Modules_CloudflarePro_SettingsRepository();

        $this->view->settings = $repository->all();
        $this->view->saveSettingsAction = pm_Context::getActionUrl('index', 'save-settings');
        $this->renderTab(
            'Settings',
            'No settings configured yet',
            'Cloudflare Pro settings will appear here when configuration options are added.',
            'settings'
        );
    }

    public function saveSettingsAction()
    {
        $this->disableRendering();

        if (!$this->_request->isPost()) {
            return $this->jsonResponse(false, 'Invalid request method.');
        }

        try {
            $repository = new Modules_CloudflarePro_SettingsRepository();
            $settings = $repository->save([
                'enable_autosync' => $this->truthyPost('enable_autosync'),
                'remove_records_on_domain_delete' => $this->truthyPost('remove_records_on_domain_delete'),
                'proxy_a' => $this->truthyPost('proxy_a'),
                'proxy_aaaa' => $this->truthyPost('proxy_aaaa'),
                'proxy_cname' => $this->truthyPost('proxy_cname'),
                'log_api_requests' => $this->truthyPost('log_api_requests'),
                'validate_token_before_sync' => $this->truthyPost('validate_token_before_sync'),
            ]);

            return $this->jsonResponse(true, 'Settings saved successfully.', [
                'settings' => $settings,
            ]);
        } catch (Throwable $e) {
            return $this->jsonResponse(false, $e->getMessage());
        }
    }

    public function aboutAction()
    {
        $this->view->aboutInfo = [
            'name' => 'Cloudflare Pro',
            'brand' => 'Ghost Compiler',
            'id' => 'cloudflare-pro',
            'version' => '1.0.0',
            'release' => '1',
            'category' => 'DNS',
            'vendorUrl' => 'https://ghostcompiler.com',
            'githubUrl' => 'https://github.com/ghostcompiler',
            'developerLogo' => 'https://assets.ghostcompiler.in/logo.png',
            'repositoryUrl' => 'https://github.com/ghostcompiler/cloudflare-pro',
            'pleskMinVersion' => '18.0.0',
            'uiLibrary' => 'Plesk UI Library',
            'uiLibraryVersion' => '3.46.5',
            'description' => 'Cloudflare Pro connects Plesk DNS zones with Cloudflare zones using per-user tokens, synced records, API logs, and autosync controls.',
        ];

        $this->renderTab(
            'About',
            'Cloudflare Pro',
            'Ghost Compiler extension for Cloudflare management.',
            'about'
        );
    }

    private function renderTab($title, $emptyTitle, $emptyDescription, $id = null)
    {
        $this->view->pageTitle = $title;
        $this->view->tabTitle = $title;
        $this->view->emptyTitle = $emptyTitle;
        $this->view->emptyDescription = $emptyDescription;
        $this->view->tabId = $id;
        $this->render('index');
    }

    private function truthyPost($key)
    {
        return in_array((string) $this->_request->getPost($key, '0'), ['1', 'true', 'yes', 'on'], true);
    }

    private function getTabs($activeAction)
    {
        return [
            [
                'id' => 'domains',
                'title' => 'Domains',
                'controller' => 'index',
                'action' => 'domains',
                'active' => in_array($activeAction, ['domains', 'records', 'index'], true),
            ],
            [
                'id' => 'tokens',
                'title' => 'Tokens',
                'controller' => 'index',
                'action' => 'tokens',
                'active' => 'tokens' === $activeAction,
            ],
            [
                'id' => 'logs',
                'title' => 'API Logs',
                'controller' => 'index',
                'action' => 'logs',
                'active' => 'logs' === $activeAction,
            ],
            [
                'id' => 'settings',
                'title' => 'Settings',
                'controller' => 'index',
                'action' => 'settings',
                'active' => 'settings' === $activeAction,
            ],
            [
                'id' => 'about',
                'title' => 'About',
                'controller' => 'index',
                'action' => 'about',
                'active' => 'about' === $activeAction,
            ],
        ];
    }

    private function jsonResponse($success, $message, array $data = array())
    {
        $this->disableRendering();

        $payload = array_merge(array(
            'success' => (bool) $success,
            'message' => $message,
        ), $data);

        http_response_code($success ? 200 : 400);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');

        echo json_encode($payload);
        exit;
    }

    private function disableRendering()
    {
        try {
            $this->_helper->layout()->disableLayout();
        } catch (Exception $e) {
        }

        try {
            $this->_helper->viewRenderer->setNoRender(true);
        } catch (Exception $e) {
        }
    }
}
