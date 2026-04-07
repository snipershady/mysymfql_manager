<?php

namespace App\Doctrine\Listener;

use App\Entity\SqlClient;
use App\Service\FieldEncryptor;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Event\PostLoadEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Events;

/**
 * Cifra la password di SqlClient prima di scriverla su DB e la decifra
 * dopo il caricamento, in modo trasparente per il resto dell'applicazione.
 *
 * Dopo il PostLoad viene aggiornato lo snapshot del UnitOfWork con il valore
 * decifrato, così Doctrine non rileva un falso "campo modificato" al prossimo
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
     * Decifra la password dopo il caricamento dal DB.
     * Aggiorna lo snapshot UoW per evitare dirty tracking spurio.
     */
    public function postLoad(SqlClient $entity, PostLoadEventArgs $args): void
    {
        $password = $entity->getPassword();

        if (null === $password || !$this->encryptor->isEncrypted($password)) {
            return;
        }

        $decrypted = $this->encryptor->decrypt($password);
        $entity->setPassword($decrypted);

        // Aggiorna lo snapshot originale nel UoW per evitare che Doctrine
        // rilevi il campo come modificato e tenti un UPDATE non necessario.
        $uow = $args->getObjectManager()->getUnitOfWork();
        $uow->setOriginalEntityProperty(spl_object_id($entity), 'password', $decrypted);
    }

    /**
     * Cifra la password prima di un INSERT.
     */
    public function prePersist(SqlClient $entity): void
    {
        $this->encryptIfNeeded($entity);
    }

    /**
     * Cifra la password prima di un UPDATE, solo se è stata effettivamente
     * modificata e il nuovo valore non è già cifrato.
     */
    public function preUpdate(SqlClient $entity, PreUpdateEventArgs $args): void
    {
        if (!$args->hasChangedField('password')) {
            return;
        }

        $this->encryptIfNeeded($entity);

        // Comunica a Doctrine il valore aggiornato del campo nel changeset.
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
