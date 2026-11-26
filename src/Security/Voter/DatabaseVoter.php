<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\AppUser;
use App\Repository\DatabaseOwnerRepository;
use App\Security\ManagedDatabase;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Panel-level authorization on a single database of a server.
 *
 * {@see self::ACCESS}: admins, or users that own the server ({@see SqlClientVoter::VIEW})
 * and have a DatabaseOwner entry for that database on that server.
 *
 * @extends Voter<string, ManagedDatabase>
 *
 * @author Stefano Perrini <perrini.stefano@gmail.com> aka La Matrigna
 */
final class DatabaseVoter extends Voter
{
    public const string ACCESS = 'DATABASE_ACCESS';

    public function __construct(
        private readonly AccessDecisionManagerInterface $accessDecisionManager,
        private readonly DatabaseOwnerRepository $databaseOwnerRepository,
    ) {
    }

    #[\Override]
    protected function supports(string $attribute, mixed $subject): bool
    {
        return self::ACCESS === $attribute && $subject instanceof ManagedDatabase;
    }

    #[\Override]
    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof AppUser) {
            return false;
        }

        if ($this->accessDecisionManager->decide($token, ['ROLE_ADMIN'])) {
            return true;
        }

        if ('' === $subject->dbName || !$this->accessDecisionManager->decide($token, [SqlClientVoter::VIEW], $subject->sqlClient)) {
            return false;
        }

        return \in_array($subject->dbName, $this->databaseOwnerRepository->findDbNamesOwnedOn($user, $subject->sqlClient), strict: true);
    }
}
