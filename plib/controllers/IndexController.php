<?php

require_once pm_Context::getPlibDir() . 'library/TokenRepository.php';
require_once pm_Context::getPlibDir() . 'library/ApiLogRepository.php';
require_once pm_Context::getPlibDir() . 'library/SettingsRepository.php';
require_once pm_Context::getPlibDir() . 'library/CloudflarePro/Permissions.php';
require_once pm_Context::getPlibDir() . 'library/CloudflarePro/CloudflareClient.php';

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
        $this->renderTab(
            'Domains',
            'No domains added yet',
            'Cloudflare connected domains will appear here after sync actions.'
        );
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
        $this->renderTab(
            'About',
            'Cloudflare Pro',
            'Ghost Compiler extension for Cloudflare management.'
        );
    }

    private function renderTab($title, $emptyTitle, $emptyDescription, $id = null)
    {
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
                'active' => 'domains' === $activeAction || 'index' === $activeAction,
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
