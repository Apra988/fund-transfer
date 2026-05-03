<?php

declare(strict_types=1);

namespace App\Command;

use Firebase\JWT\JWT;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:security:issue-token',
    description: 'Issue a signed HS256 JWT for API testing (JWT_SECRET_KEY must be set)',
)]
final class IssueJwtTokenCommand extends Command
{
    public function __construct(
        private readonly string $jwtSecretKey,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('subject', InputArgument::REQUIRED, 'sub claim (client / tenant id)')
            ->addOption('ttl', null, InputOption::VALUE_REQUIRED, 'Lifetime in seconds', '3600');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $secret = trim($this->jwtSecretKey);

        if (strlen($secret) < 32) {
            $io->error('JWT_SECRET_KEY must be at least 32 characters.');

            return Command::FAILURE;
        }

        $subject = (string) $input->getArgument('subject');
        $ttl = max(60, (int) $input->getOption('ttl'));
        $now = time();

        $payload = [
            'iat' => $now,
            'nbf' => $now,
            'exp' => $now + $ttl,
            'sub' => $subject,
            'roles' => ['TRANSFER'],
        ];

        $token = JWT::encode($payload, $secret, 'HS256');

        $io->writeln($token);

        return Command::SUCCESS;
    }
}
