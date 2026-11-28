<?php

declare(strict_types=1);

namespace App\Security\Grants;

use App\Entity\AppUser;
use App\Entity\DatabaseOwner;
use App\Entity\SqlClient;
use App\Enum\SeverityEnum;

/**
 * Checks that the MySQL account of a registered server (SqlClient) cannot reach more than its panel users may
 * manage. The query console runs arbitrary SQL with that account, so for non-admin users its grants are the only
 * barrier between their databases and the others on the server (decision of 2026-11-28: one account per server,
 * granted only on the databases of its users).
 *
 * Findings, when at least one non-admin user reaches the server (otherwise broad privileges are expected and only
 * reported as info):
 * - danger: global privileges (ON *.*), roles, PROXY, wildcard database grants, grants on databases not assigned
 *   to any user of the server, one account shared by non-admin users with different databases;
 * - warning: WITH GRANT OPTION (users can hand their privileges to other accounts), lines not understood.
 *
 * Pure: the caller passes the SHOW GRANTS output and the catalog data.
 *
 * @see \App\Tests\Security\Grants\ServerGrantCheckerTest
 */
final readonly class ServerGrantChecker
{
    public function __construct(
        private GrantStatementParser $parser = new GrantStatementParser(),
    ) {
    }

    /**
     * @param list<string>        $grants      SHOW GRANTS output of the server account
     * @param list<DatabaseOwner> $assignments every DatabaseOwner on this server
     */
    public function check(SqlClient $sqlClient, string $account, array $grants, array $assignments): ServerGrantReport
    {
        $parsed = $this->parser->parse($grants);

        /** @var array<string, list<string>> $dbsByNonAdmin */
        $dbsByNonAdmin = [];
        foreach ($sqlClient->getOwner() as $owner) {
            if (!$this->isAdmin($owner)) {
                $dbsByNonAdmin[(string) $owner->getUsername()] ??= [];
            }
        }

        $assignedDbs = [];
        foreach ($assignments as $assignment) {
            $db = (string) $assignment->getDbName();
            $assignedDbs[$db] = true;
            $owner = $assignment->getOwner();
            if ($owner instanceof AppUser && !$this->isAdmin($owner)) {
                $dbsByNonAdmin[(string) $owner->getUsername()][] = $db;
            }
        }

        $findings = [];
        $nonAdmins = array_keys($dbsByNonAdmin);
        if ([] === $nonAdmins) {
            $findings[] = $this->finding(SeverityEnum::INFO, [] === $parsed->globalPrivileges
                ? 'Usato solo da admin.'
                : \sprintf('Usato solo da admin: i privilegi globali (%s) sono accettabili.', $this->list($parsed->globalPrivileges)));

            return $this->report($sqlClient, $account, $findings, $grants, $nonAdmins, $assignedDbs);
        }

        if ([] !== $parsed->globalPrivileges) {
            $findings[] = $this->finding(SeverityEnum::DANGER, \sprintf(
                'Privilegi globali (%s): valgono su tutti i database del server, anche quelli non assegnati, e dalla query console li usano anche gli utenti non admin.',
                $this->list($parsed->globalPrivileges),
            ));
        }

        if ([] !== $parsed->roles) {
            $findings[] = $this->finding(SeverityEnum::DANGER, \sprintf("Ruoli assegnati all'account (%s): i loro privilegi non sono verificabili da qui.", implode(', ', $parsed->roles)));
        }

        if ($parsed->proxy) {
            $findings[] = $this->finding(SeverityEnum::DANGER, "Privilegio PROXY: l'account può agire come altri utenti MySQL.");
        }

        foreach (array_keys($parsed->databases) as $pattern) {
            if (ParsedGrants::isWildcard($pattern)) {
                $findings[] = $this->finding(SeverityEnum::DANGER, \sprintf('Grant con wildcard su `%s`: copre anche database non assegnati.', $pattern));
            } elseif (!isset($assignedDbs[ParsedGrants::literalName($pattern)])) {
                $findings[] = $this->finding(SeverityEnum::DANGER, \sprintf('Grant sul database `%s`, non assegnato a nessun utente di questo server: è leggibile dalla query console.', ParsedGrants::literalName($pattern)));
            }
        }

        // Only users with databases use the query console (it needs a DatabaseOwner): compare their sets.
        $withDatabases = array_filter($dbsByNonAdmin, static fn (array $dbs): bool => [] !== $dbs);
        $distinctSets = array_unique(array_map(static function (array $dbs): string {
            $dbs = array_unique($dbs);
            sort($dbs);

            return implode("\0", $dbs);
        }, $withDatabases));
        if (\count($distinctSets) > 1) {
            $users = array_map(strval(...), array_keys($withDatabases));
            sort($users);
            $findings[] = $this->finding(SeverityEnum::DANGER, \sprintf(
                'Account condiviso da utenti non admin con database diversi (%s): dalla query console ognuno può raggiungere i database degli altri. Registra un server (e un account MySQL) per utente.',
                implode(', ', $users),
            ));
        }

        if ($parsed->grantOption) {
            $findings[] = $this->finding(SeverityEnum::WARNING, "WITH GRANT OPTION: chi usa l'account può concedere i propri privilegi ad altri account MySQL.");
        }

        foreach ($parsed->unparsed as $line) {
            $findings[] = $this->finding(SeverityEnum::WARNING, 'Grant non riconosciuto, da verificare a mano: ' . $line);
        }

        if ([] === $findings) {
            $findings[] = $this->finding(SeverityEnum::OK, 'I grant coprono solo i database assegnati ai suoi utenti.');
        }

        return $this->report($sqlClient, $account, $findings, $grants, $nonAdmins, $assignedDbs);
    }

    /**
     * Report of a server that could not be checked (connection or query error).
     */
    public function unreachable(SqlClient $sqlClient, string $error): ServerGrantReport
    {
        return new ServerGrantReport((string) $sqlClient->getName(), account: null, findings: [], error: $error);
    }

    /**
     * @param list<array{severity: SeverityEnum, message: string}> $findings
     * @param list<string>                                         $grants
     * @param list<string>                                         $nonAdmins
     * @param array<string, true>                                  $assignedDbs
     */
    private function report(SqlClient $sqlClient, string $account, array $findings, array $grants, array $nonAdmins, array $assignedDbs): ServerGrantReport
    {
        $dbs = array_map(strval(...), array_keys($assignedDbs));
        sort($dbs);
        sort($nonAdmins);

        return new ServerGrantReport((string) $sqlClient->getName(), $account, $findings, $grants, $nonAdmins, $dbs);
    }

    /**
     * @return array{severity: SeverityEnum, message: string}
     */
    private function finding(SeverityEnum $severity, string $message): array
    {
        return ['severity' => $severity, 'message' => $message];
    }

    /**
     * @param list<string> $privileges
     */
    private function list(array $privileges): string
    {
        return \count($privileges) > 6 ? implode(', ', \array_slice($privileges, 0, 6)) . ', …' : implode(', ', $privileges);
    }

    private function isAdmin(AppUser $user): bool
    {
        return \in_array('ROLE_ADMIN', $user->getRoles(), strict: true);
    }
}
