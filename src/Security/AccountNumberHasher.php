<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Empreinte et masque des numéros de compte : le numéro en clair n'est jamais stocké
 * (voir docs/adr/0007-donnees-privees.md).
 */
final class AccountNumberHasher
{
    public function __construct(
        #[Autowire('%env(ACCOUNT_NUMBER_PEPPER)%')]
        private readonly string $pepper,
    ) {
        if ('' === $pepper) {
            throw new \InvalidArgumentException('La variable ACCOUNT_NUMBER_PEPPER ne doit pas être vide.');
        }
    }

    /** Retire les espaces et passe en majuscules. */
    public function normalize(string $raw): string
    {
        return strtoupper(preg_replace('/\s+/', '', $raw) ?? $raw);
    }

    /** Empreinte HMAC-SHA256 du numéro normalisé. */
    public function hash(string $raw): string
    {
        return hash_hmac('sha256', $this->normalize($raw), $this->pepper);
    }

    /** Masque affiché, ex `****R999`. */
    public function mask(string $raw): string
    {
        return '****'.substr($this->normalize($raw), -4);
    }
}
