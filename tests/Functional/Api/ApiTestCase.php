<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\Account;
use App\Entity\Category;
use App\Entity\Household;
use App\Entity\Member;
use App\Entity\Operation;
use App\Enum\CategorizationSource;
use App\Setup\CategoryResolver;
use App\Tests\Support\CategorizationFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Base des tests fonctionnels de l'API JSON (base de test, données fictives uniquement).
 * Fournit un foyer, un membre connectable (alice / secret), deux comptes et l'arbre
 * de catégories par défaut, plus des fabriques d'opérations.
 */
abstract class ApiTestCase extends WebTestCase
{
    protected const PASSWORD = 'secret';

    protected KernelBrowser $testClient;
    protected Household $household;
    protected Member $member;
    protected Member $otherMember;
    protected Account $account;
    protected Account $jointAccount;

    protected function setUp(): void
    {
        // createClient() amorce le noyau (un seul client par test).
        $this->testClient = static::createClient();
        // Comme le front (axios) : exigé sur les écritures /api (JsonContentTypeListener).
        $this->testClient->setServerParameter('HTTP_X_REQUESTED_WITH', 'XMLHttpRequest');
        $entityManager = $this->em();

        $this->household = new Household('Foyer test');
        $entityManager->persist($this->household);

        $this->member = new Member($this->household, 'alice', 'Alice');
        $this->member->setPassword(self::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($this->member, self::PASSWORD));
        $entityManager->persist($this->member);

        $this->otherMember = new Member($this->household, 'bob', 'Bob');
        $this->otherMember->setPassword(self::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($this->otherMember, self::PASSWORD));
        $entityManager->persist($this->otherMember);

        // Compte « ccp-perso » = même empreinte HMAC que le n° 1234567R999 des fixtures CSV
        // (tests/Fixtures/comptes.ini) : l'import via l'API le résout sans créer de compte.
        $hasher = self::getContainer()->get(\App\Security\AccountNumberHasher::class);
        $this->account = new Account(
            $this->household,
            'ccp-perso',
            'Compte '.$hasher->mask('1234567R999'),
            \App\Enum\AccountType::Personal,
            $hasher->hash('1234567R999'),
            $hasher->mask('1234567R999'),
        );
        $this->account->addHolder($this->member);
        $entityManager->persist($this->account);

        $this->jointAccount = CategorizationFixtures::makeAccount($this->household, 'compte-joint');
        $this->jointAccount->setType(\App\Enum\AccountType::Joint);
        $this->jointAccount->addHolder($this->member);
        $this->jointAccount->addHolder($this->otherMember);
        $entityManager->persist($this->jointAccount);

        CategorizationFixtures::seedCategories($entityManager, $this->household);
        $entityManager->flush();
    }

    /** EntityManager du noyau courant (à relire après une requête : le client reboote le noyau). */
    protected function em(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }

    protected function client(bool $loggedIn = true): KernelBrowser
    {
        if ($loggedIn) {
            $this->testClient->loginUser($this->member);
        }

        return $this->testClient;
    }

    /** Requête JSON (Content-Type application/json) ; body null = pas de corps. */
    protected function json(KernelBrowser $client, string $method, string $uri, ?array $body = null): Response
    {
        $client->request($method, $uri, [], [], ['CONTENT_TYPE' => 'application/json'], null !== $body ? (string) json_encode($body) : null);

        return $client->getResponse();
    }

    /** @return array<string, mixed> */
    protected function decode(Response $response): array
    {
        return json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }

    protected function category(string $fullName): Category
    {
        return self::getContainer()->get(CategoryResolver::class)->require($this->household, $fullName);
    }

    protected function addOperation(
        Account $account,
        string $date,
        int $amount,
        string $rawLabel,
        ?string $merchant = null,
        ?Category $category = null,
        ?CategorizationSource $source = null,
    ): Operation {
        // Après une requête du client, l'entity manager est neuf : on recharge le compte.
        $managedAccount = $this->em()->find(Account::class, $account->getId()) ?? $account;
        $operation = CategorizationFixtures::makeOperation($managedAccount, $date, $amount, $rawLabel, $merchant);
        if (null !== $category) {
            $operation->categorize($category, $source ?? CategorizationSource::Manual);
        }
        $this->em()->persist($operation);
        $this->em()->flush();

        return $operation;
    }
}
