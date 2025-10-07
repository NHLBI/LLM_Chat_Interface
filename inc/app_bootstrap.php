<?php
declare(strict_types=1);

class AppBootstrap
{
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * Perform the legacy bootstrap steps and return commonly-used values.
     */
    public function initialize(): array
    {
        $sessionTimeout = $this->guardSession();
        $user           = $this->resolveUser();
        $application    = $this->config['app']['application_path'] ?? '';
        $chatId         = $this->normalizeChatId();

        if (!verify_user_chat($user, $chatId)) {
            $sanitizedUser = htmlspecialchars($user, ENT_QUOTES, 'UTF-8');
            echo " -- {$sanitizedUser}<br>\n";
            die(
                'Error: there is no chat record for the specified user and chat id. '
                . 'If you need assistance, please contact '
                . htmlspecialchars($this->config['app']['emailhelp'] ?? '', ENT_QUOTES, 'UTF-8')
            );
        }

        $models       = $this->buildModels();
        $temperatures = $this->buildTemperatureScale();

        $this->handleModelSelection($user, $chatId, $models);
        $allChats = get_all_chats($user);

        $deployment = $this->hydrateSessionPreferences($user, $chatId, $allChats);
        $context    = (int)($this->config[$deployment]['context_limit'] ?? 0);

        $this->handlePreferencePosts($user, $chatId);

        $_SESSION['reasoning_effort'] = $_SESSION['reasoning_effort'] ?? 'medium';
        $_SESSION['verbosity']        = $_SESSION['verbosity']        ?? 'medium';

        return [
            'sessionTimeout'   => $sessionTimeout,
            'user'             => $user,
            'application_path' => $application,
            'chat_id'          => $chatId,
            'models'           => $models,
            'temperatures'     => $temperatures,
            'deployment'       => $_SESSION['deployment'],
            'context_limit'    => $context,
        ];
    }

    private function guardSession(): int
    {
        if (!waitForUserSession()) {
            require_once __DIR__ . '/../splash.php';
            exit;
        }

        if (empty($_SESSION['splash'])) {
            $_SESSION['splash'] = '';
        }

        if ((!empty($_SESSION['user_data']['userid']) && ($_SESSION['authorized'] ?? false) !== true)
            || empty($_SESSION['splash'])) {
            require_once __DIR__ . '/../splash.php';
            exit;
        }

        $timeout = (int)($this->config['session']['timeout'] ?? 0);
        if ($timeout > 0 && isset($_SESSION['LAST_ACTIVITY'])
            && (time() - $_SESSION['LAST_ACTIVITY'] > $timeout)) {
            logout();
        }
        $_SESSION['LAST_ACTIVITY'] = time();

        if (empty($_SESSION['user_data'])) {
            $_SESSION['user_data'] = [];
        }

        if (isAuthenticated()) {
            if (empty($_SESSION['LAST_REGEN']) || (time() - $_SESSION['LAST_REGEN'] > 900)) {
                session_regenerate_id(true);
                $_SESSION['LAST_REGEN'] = time();
            }
        } else {
            header('Location: auth_redirect.php');
            exit;
        }

        return $timeout;
    }

    private function resolveUser(): string
    {
        return (string)($_SESSION['user_data']['userid'] ?? '');
    }

    private function normalizeChatId(): string
    {
        $chatId = filter_input(INPUT_GET, 'chat_id', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        if (empty($_GET['chat_id'])) {
            $_GET['chat_id'] = '';
        }
        return $chatId ?? '';
    }

    private function buildModels(): array
    {
        $models = [];
        $modelsStr = $this->config['azure']['deployments'] ?? '';
        foreach (explode(',', $modelsStr) as $token) {
            $parts = array_map('trim', explode(':', $token));
            if (count($parts) !== 2) {
                continue;
            }
            $key = $parts[0];
            $label = $parts[1];
            $models[$key] = ['label' => $label] + ($this->config[$key] ?? []);
        }
        return $models;
    }

    private function buildTemperatureScale(): array
    {
        $temps = [];
        $i = 0.0;
        while ($i < 2.1) {
            $temps[] = round($i, 1);
            $i += 0.1;
        }
        return $temps;
    }

    private function handleModelSelection(string $user, string $chatId, array $models): void
    {
        if (!isset($_POST['model'])) {
            return;
        }

        $chosen = (string)$_POST['model'];
        if (!array_key_exists($chosen, $models)) {
            return;
        }

        $_SESSION['deployment'] = $chosen;
        if (!empty($chatId)) {
            update_deployment($user, $chatId, $chosen);
        }
    }

    private function hydrateSessionPreferences(string $user, string $chatId, array $allChats): string
    {
        if (!empty($chatId) && !empty($allChats[$chatId])) {
            $chatRow = $allChats[$chatId];
            $_SESSION['deployment'] = $chatRow['deployment'];

            $_SESSION['temperature'] = ($chatRow['temperature'] ?? '') !== ''
                ? $chatRow['temperature']
                : ($_SESSION['temperature'] ?? '0.7');

            $allowedEffort = ['minimal', 'low', 'medium', 'high'];
            $allowedVerb   = ['low', 'medium', 'high'];

            $effortDb   = $chatRow['reasoning_effort'] ?? null;
            $verbosityDb= $chatRow['verbosity']        ?? null;

            $_SESSION['reasoning_effort'] = in_array($effortDb, $allowedEffort, true)
                ? $effortDb
                : ($_SESSION['reasoning_effort'] ?? 'medium');

            $_SESSION['verbosity'] = in_array($verbosityDb, $allowedVerb, true)
                ? $verbosityDb
                : ($_SESSION['verbosity'] ?? 'medium');
        } else {
            $_SESSION['temperature']      = $_SESSION['temperature']      ?? '0.7';
            $_SESSION['reasoning_effort'] = $_SESSION['reasoning_effort'] ?? 'medium';
            $_SESSION['verbosity']        = $_SESSION['verbosity']        ?? 'medium';
        }

        if (empty($_SESSION['deployment'])) {
            $_SESSION['deployment'] = $this->config['azure']['default'] ?? '';
        }

        return (string)($_SESSION['deployment'] ?? '');
    }

    private function handlePreferencePosts(string $user, string $chatId): void
    {
        if (isset($_POST['temperature'])) {
            $temperature = (float)$_POST['temperature'];
            $_SESSION['temperature'] = $temperature;
            if (!empty($chatId)) {
                update_temperature($user, $chatId, $temperature);
            }
        }

        if (!isset($_SESSION['temperature'])
            || (float)$_SESSION['temperature'] < 0
            || (float)$_SESSION['temperature'] > 2) {
            $_SESSION['temperature'] = 0.7;
        }

        if (isset($_POST['reasoning_effort'])) {
            $allowed = ['minimal', 'low', 'medium', 'high'];
            $val = strtolower((string)$_POST['reasoning_effort']);
            $val = in_array($val, $allowed, true) ? $val : 'medium';
            $_SESSION['reasoning_effort'] = $val;
            if (!empty($chatId)) {
                update_reasoning_effort($user, $chatId, $val);
            }
        }

        if (isset($_POST['verbosity'])) {
            $allowed = ['low', 'medium', 'high'];
            $val = strtolower((string)$_POST['verbosity']);
            $val = in_array($val, $allowed, true) ? $val : 'medium';
            $_SESSION['verbosity'] = $val;
            if (!empty($chatId)) {
                update_verbosity($user, $chatId, $val);
            }
        }
    }
}
