<?php

declare(strict_types=1);

namespace Impulse\Db\Internal;

use Impulse\Core\Support\Collector\ScriptCollector;
use JsonException;

final class NotificationManager
{
    private const SESSION_KEY = '_impulse_db_notifications';
    /**
     * @var array<string, true>
     */
    private static array $rendered = [];

    public function success(string $message): void
    {
        $this->push('success', $message);
    }

    public function error(string $message): void
    {
        $this->push('error', $message);
    }

    /**
     * @throws JsonException
     */
    public function flushToResponse(): void
    {
        $notifications = $this->isAjaxRequest()
            ? $this->peek()
            : $this->consume();

        if ($notifications === []) {
            return;
        }

        $this->renderNotifications($notifications);
    }

    /**
     * @param list<array{type: string, message: string}> $notifications
     * @throws JsonException
     */
    private function renderNotifications(array $notifications): void
    {
        $notifications = array_values(array_filter(
            $notifications,
            fn (array $notification): bool => !$this->isRendered($notification),
        ));

        if ($notifications === []) {
            return;
        }

        foreach ($notifications as $notification) {
            self::$rendered[$this->hash($notification)] = true;
        }

        $payload = json_encode($notifications, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        ScriptCollector::addCode(<<<JS
        (function () {
            const notifications = {$payload};
            const palette = {
                success: {
                    border: '#10b981',
                    background: '#ecfdf5',
                    color: '#065f46'
                },
                error: {
                    border: '#ef4444',
                    background: '#fef2f2',
                    color: '#991b1b'
                }
            };
        
            function renderToast(notification) {
                const type = palette[notification.type] || palette.success;
                const containerId = 'impulse-db-toast-container';
                let container = document.getElementById(containerId);
        
                if (!container) {
                    container = document.createElement('div');
                    container.id = containerId;
                    container.style.position = 'fixed';
                    container.style.top = '1rem';
                    container.style.right = '1rem';
                    container.style.zIndex = '9999';
                    container.style.display = 'flex';
                    container.style.flexDirection = 'column';
                    container.style.gap = '0.75rem';
                    document.body.appendChild(container);
                }
        
                const toast = document.createElement('div');
                toast.className = 'ui-toast';
                toast.setAttribute('data-toast-entering', 'true');
                toast.style.maxWidth = '28rem';
                toast.style.minWidth = '18rem';
                toast.style.border = '1px solid ' + type.border;
                toast.style.borderRadius = '0.75rem';
                toast.style.background = type.background;
                toast.style.color = type.color;
                toast.style.boxShadow = '0 10px 25px rgba(15, 23, 42, 0.12)';
                toast.style.padding = '0.875rem 1rem';
                toast.style.fontFamily = 'ui-sans-serif, system-ui, sans-serif';
                toast.style.lineHeight = '1.4';
        
                const title = document.createElement('div');
                title.style.fontWeight = '700';
                title.style.marginBottom = '0.25rem';
                title.textContent = notification.type === 'error' ? 'Erreur' : 'Succès';
        
                const message = document.createElement('div');
                message.style.whiteSpace = 'pre-line';
                message.textContent = notification.message;
        
                toast.appendChild(title);
                toast.appendChild(message);
                container.appendChild(toast);
        
                window.setTimeout(function () {
                    toast.style.opacity = '0';
                    toast.style.transform = 'translateY(-6px)';
                    toast.style.transition = 'opacity 180ms ease, transform 180ms ease';
                    window.setTimeout(function () {
                        toast.remove();
                        if (container && container.childElementCount === 0) {
                            container.remove();
                        }
                    }, 300);
                }, 8000);
            }
        
            function boot() {
                notifications.forEach(renderToast);
            }
        
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', boot, { once: true });
            } else {
                boot();
            }
        })();
        JS);
    }

    private function push(string $type, string $message): void
    {
        if ($message === '') {
            return;
        }

        $this->startSession();
        $_SESSION[self::SESSION_KEY] ??= [];
        $_SESSION[self::SESSION_KEY][] = [
            'type' => $type,
            'message' => $message,
        ];
        $this->renderNotifications([[
            'type' => $type,
            'message' => $message,
        ]]);
    }

    /**
     * @return array<int, array{type: string, message: string}>
     */
    private function consume(): array
    {
        $this->startSession();
        $notifications = $_SESSION[self::SESSION_KEY] ?? [];
        unset($_SESSION[self::SESSION_KEY]);

        return is_array($notifications) ? \array_values($notifications) : [];
    }

    /**
     * @return array<int, array{type: string, message: string}>
     */
    private function peek(): array
    {
        $this->startSession();
        $notifications = $_SESSION[self::SESSION_KEY] ?? [];

        return is_array($notifications) ? \array_values($notifications) : [];
    }

    private function isAjaxRequest(): bool
    {
        $requestedWith = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));
        if ($requestedWith === 'xmlhttprequest') {
            return true;
        }

        $contentType = strtolower(($_SERVER['CONTENT_TYPE'] ?? ''));
        if (str_contains($contentType, 'application/json')) {
            return true;
        }

        $accept = strtolower(($_SERVER['HTTP_ACCEPT'] ?? ''));

        return str_contains($accept, 'application/json');
    }

    private function startSession(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    /**
     * @param array{type: string, message: string} $notification
     */
    private function isRendered(array $notification): bool
    {
        return isset(self::$rendered[$this->hash($notification)]);
    }

    /**
     * @param array{type: string, message: string} $notification
     */
    private function hash(array $notification): string
    {
        return sha1($notification['type'] . "\0" . $notification['message']);
    }
}
