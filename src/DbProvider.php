<?php

declare(strict_types=1);

namespace Impulse\Db;

use Cycle\Database\DatabaseManager;
use Cycle\ORM\ORMInterface;
use Impulse\Core\Container\ImpulseContainer;
use Impulse\Core\Provider\AbstractProvider;
use Impulse\Database\Contrats\DatabaseInterface;
use Impulse\Db\Contracts\DbInterface;
use JsonException;
use PDO;

final class DbProvider extends AbstractProvider
{
    /**
     * @throws JsonException
     */
    protected function registerServices(ImpulseContainer $container): void
    {
        $container->set(DbInterface::class, static fn () => new Db());
        $container->set(Db::class, static fn (ImpulseContainer $container) => $container->get(DbInterface::class));
        $container->set(DatabaseManager::class, static fn (ImpulseContainer $container) => $container->get(DbInterface::class)->getDatabaseManager());
        $container->set(ORMInterface::class, static fn (ImpulseContainer $container) => $container->get(DbInterface::class)->getORM());
        $container->set(PDO::class, static fn (ImpulseContainer $container) => $container->get(Db::class)->getPdo());

        if (interface_exists(DatabaseInterface::class)) {
            $container->set(DatabaseInterface::class, static function (ImpulseContainer $container) {
                $db = $container->get(DbInterface::class);

                return new class ($db) implements DatabaseInterface {
                    public function __construct(private readonly DbInterface $db) {}

                    public function getDatabase(?string $name = null): \Cycle\Database\DatabaseInterface
                    {
                        return $this->db->getDatabase($name);
                    }

                    public function getORM(): ORMInterface
                    {
                        return $this->db->getORM();
                    }

                    public function getConfig(): array
                    {
                        return $this->db->getConfig();
                    }
                };
            });
        }
    }

    /**
     * @throws JsonException
     */
    protected function onBoot(ImpulseContainer $container): void
    {
        try {
            /** @var Db $db */
            $db = $container->get(DbInterface::class);
            $db->flushNotifications();
        } catch (\Throwable $e) {
            $db = new Db(false);
            $db->pushErrorNotification($e->getMessage());
            $db->flushNotifications();
        }
    }
}
