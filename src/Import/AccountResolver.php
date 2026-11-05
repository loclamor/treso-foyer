<?php

declare(strict_types=1);

namespace App\Import;

use App\Entity\Account;
use App\Entity\Household;
use App\Enum\AccountType;
use App\Import\Exception\UnknownAccountException;
use App\Repository\AccountRepository;
use App\Repository\HouseholdRepository;
use App\Security\AccountNumberHasher;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Résout un numéro de compte lu dans un relevé vers l'entité Account (par son empreinte HMAC),
 * avec création à la volée si demandé. Le numéro en clair n'est jamais journalisé.
 */
final class AccountResolver
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AccountNumberHasher $hasher,
        #[Autowire('%app.accounts_ini%')]
        private readonly string $accountsIni,
    ) {
    }

    /**
     * @throws UnknownAccountException si compte introuvable et $autoCreate à false,
     *                                 ou aucun foyer en base (message avec masque uniquement)
     */
    public function resolve(string $rawNumber, bool $autoCreate): Account
    {
        /** @var AccountRepository $accountRepository */
        $accountRepository = $this->entityManager->getRepository(Account::class);
        $hash = $this->hasher->hash($rawNumber);
        $account = $accountRepository->findOneBy(['numberHash' => $hash]);
        if ($account instanceof Account) {
            return $account;
        }

        $mask = $this->hasher->mask($rawNumber);
        if (!$autoCreate) {
            throw new UnknownAccountException(sprintf(
                'Compte %s inconnu : importez d’abord avec --auto-create-account.',
                $mask,
            ));
        }

        /** @var HouseholdRepository $householdRepository */
        $householdRepository = $this->entityManager->getRepository(Household::class);
        $household = $householdRepository->findOneBy([]);
        if (!$household instanceof Household) {
            throw new UnknownAccountException('Aucun foyer en base : lancez app:setup.');
        }

        $normalized = $this->hasher->normalize($rawNumber);
        $alias = $this->readAlias($normalized) ?? 'compte-'.strtolower(substr($normalized, -4));

        $account = new Account(
            $household,
            $alias,
            'Compte '.$mask,
            AccountType::Personal,
            $hash,
            $mask,
        );
        $this->entityManager->persist($account);
        // flush pour obtenir l'id, utilisé dans les fingerprints des opérations.
        $this->entityManager->flush();

        return $account;
    }

    /**
     * Alias lu dans l'INI des comptes (section [comptes], clé = numéro normalisé sans espaces).
     * Le fichier peut ne pas exister. Les lignes commençant par « # » (écrites par tools/ranger_releves.py)
     * sont retirées avant l'analyse : PHP ne les reconnaît pas comme commentaires et échouerait sur leur contenu.
     */
    private function readAlias(string $normalizedNumber): ?string
    {
        $content = is_file($this->accountsIni) ? file_get_contents($this->accountsIni) : false;
        if (false === $content) {
            return null;
        }
        $content = preg_replace('/^\s*#.*$/m', '', $content) ?? $content;
        $ini = @parse_ini_string($content, true, INI_SCANNER_RAW);
        if (!is_array($ini)) {
            return null;
        }
        $comptes = $ini['comptes'] ?? null;
        if (!is_array($comptes)) {
            return null;
        }
        $alias = $comptes[$normalizedNumber] ?? null;

        return is_string($alias) && '' !== trim($alias) ? trim($alias) : null;
    }
}
