<?php

namespace App\Doctrine\Listener;

use App\Entity\SqlClient;
use App\Service\FieldEncryptor;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Event\PostLoadEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Events;

/**
 * Encrypts the SqlClient password before writing it to the DB and decrypts it
 * after loading, in a way that is transparent to the rest of the application.
 *
 * After PostLoad the UnitOfWork snapshot is updated with the decrypted value,
 * so Doctrine does not detect a false "modified field" on the next
 * flush (dirty tracking prevention).
 */
#[AsEntityListener(event: Events::postLoad, entity: SqlClient::class)]
#[AsEntityListener(event: Events::prePersist, entity: SqlClient::class)]
#[AsEntityListener(event: Events::preUpdate, entity: SqlClient::class)]
final readonly class SqlClientListener
{
    public function __construct(private FieldEncryptor $encryptor)
    {
    }

    /**
     * Decrypts the password after loading from the DB.
     * Updates the UoW snapshot to avoid spurious dirty tracking.
     */
    public function postLoad(SqlClient $entity, PostLoadEventArgs $args): void
    {
        $password = $entity->getPassword();

        if (null === $password || !$this->encryptor->isEncrypted($password)) {
            return;
        }

        $decrypted = $this->encryptor->decrypt($password);
        $entity->setPassword($decrypted);

        // Update the original snapshot in the UoW to prevent Doctrine
        // from detecting the field as modified and attempting an unnecessary UPDATE.
        $uow = $args->getObjectManager()->getUnitOfWork();
        $uow->setOriginalEntityProperty(spl_object_id($entity), 'password', $decrypted);
    }

    /**
     * Encrypts the password before an INSERT.
     */
    public function prePersist(SqlClient $entity): void
    {
        $this->encryptIfNeeded($entity);
    }

    /**
     * Encrypts the password before an UPDATE, only if it was actually
     * changed and the new value is not already encrypted.
     */
    public function preUpdate(SqlClient $entity, PreUpdateEventArgs $args): void
    {
        if (!$args->hasChangedField('password')) {
            return;
        }

        $this->encryptIfNeeded($entity);

        // Notify Doctrine of the updated field value in the changeset.
        $args->setNewValue('password', $entity->getPassword());
    }

    private function encryptIfNeeded(SqlClient $entity): void
    {
        $password = $entity->getPassword();

        if (null !== $password && !$this->encryptor->isEncrypted($password)) {
            $entity->setPassword($this->encryptor->encrypt($password));
        }
    }
}
