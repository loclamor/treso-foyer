<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Household;
use App\Entity\Member;
use App\Repository\HouseholdRepository;
use App\Repository\MemberRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(name: 'app:member:add', description: 'Ajoute un membre au foyer')]
final class MemberAddCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('username', InputArgument::REQUIRED, 'Nom d’utilisateur (identifiant de connexion)');
        $this->addArgument('displayName', InputArgument::REQUIRED, 'Nom affiché');
        $this->addOption('password', null, InputOption::VALUE_OPTIONAL, 'Mot de passe en clair (haché avant stockage)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var HouseholdRepository $householdRepository */
        $householdRepository = $this->entityManager->getRepository(Household::class);
        $household = $householdRepository->findOneBy([]);
        if (null === $household) {
            $io->error('Aucun foyer trouvé. Lancez d’abord la commande app:setup.');

            return Command::FAILURE;
        }

        $username = (string) $input->getArgument('username');
        $displayName = (string) $input->getArgument('displayName');

        /** @var MemberRepository $memberRepository */
        $memberRepository = $this->entityManager->getRepository(Member::class);
        if (null !== $memberRepository->findOneBy(['username' => $username])) {
            $io->error(sprintf('Le nom d’utilisateur « %s » est déjà pris.', $username));

            return Command::FAILURE;
        }

        $password = $input->getOption('password');
        if (null === $password && $input->isInteractive()) {
            $password = $io->askHidden('Mot de passe (laisser vide pour aucun mot de passe)');
        }

        $member = new Member($household, $username, $displayName);
        if (is_string($password) && '' !== $password) {
            $member->setPassword($this->passwordHasher->hashPassword($member, $password));
        }

        $this->entityManager->persist($member);
        $this->entityManager->flush();

        $io->success(sprintf(
            'Membre « %s » (%s) ajouté au foyer « %s ».',
            $member->getUsername(),
            $member->getDisplayName(),
            $household->getName(),
        ));

        return Command::SUCCESS;
    }
}
