<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Account;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Uid\Uuid;

#[AsCommand(name: 'app:seed-demo-accounts', description: 'Create two demo accounts with starting balances (development helper).')]
final class SeedDemoAccountsCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $a = new Account(Uuid::v4()->toRfc4122(), '100000');
        $b = new Account(Uuid::v4()->toRfc4122(), '50000');
        $this->em->persist($a);
        $this->em->persist($b);
        $this->em->flush();

        $io->success(sprintf(
            'Created accounts: %s (balanceMinor=%s), %s (balanceMinor=%s)',
            $a->getPublicId(),
            $a->getBalanceMinor(),
            $b->getPublicId(),
            $b->getBalanceMinor(),
        ));

        return Command::SUCCESS;
    }
}
