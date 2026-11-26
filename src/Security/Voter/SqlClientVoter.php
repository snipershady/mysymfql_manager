<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\AppUser;
use App\Entity\SqlClient;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Panel-level authorization on a {@see SqlClient}.
 *
 * - {@see self::VIEW}: admins, or users listed among the server owners.
 * - {@see self::MANAGE}: admins only (create/edit/delete servers and their credentials).
 *
 * @extends Voter<string, SqlClient>
 *
 * @author Stefano Perrini <perrini.stefano@gmail.com> aka La Matrigna
 *
 * @see \App\Tests\Security\Voter\SqlClientVoterTest
 */
final class SqlClientVoter extends Voter
{
    public const string VIEW = 'SQLCLIENT_VIEW';

    public const string MANAGE = 'SQLCLIENT_MANAGE';

    public function __construct(private readonly AccessDecisionManagerInterface $accessDecisionManager)
    {
    }

    #[\Override]
    protected function supports(string $attribute, mixed $subject): bool
    {
        return \in_array($attribute, [self::VIEW, self::MANAGE], strict: true) && $subject instanceof SqlClient;
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

        if (self::MANAGE === $attribute) {
            return false;
        }

        $userId = $user->getId();

        return null !== $userId && $subject->getOwner()->exists(
            static fn (int|string $key, AppUser $owner): bool => $owner->getId() === $userId
        );
    }
}
