<?php
declare(strict_types=1);

namespace Zwernemann\ConversationalCommerce\Console\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Zwernemann\ConversationalCommerce\Api\MessageProcessorInterface;
use Zwernemann\ConversationalCommerce\Model\Channel\Email\EmailChannel;

class PollMailCommand extends Command
{
    public function __construct(
        private readonly EmailChannel             $emailChannel,
        private readonly MessageProcessorInterface $processor
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('cc:mail:poll')
            ->setDescription('Poll IMAP mailbox once and process all new messages')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Parse messages but do not send responses or create orders');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dryRun   = (bool)$input->getOption('dry-run');
        $messages = $this->emailChannel->pollMessages();

        if (empty($messages)) {
            $output->writeln('<info>No new messages.</info>');
            return Command::SUCCESS;
        }

        $output->writeln(sprintf('<info>Found %d new message(s).</info>', count($messages)));

        foreach ($messages as $i => $msg) {
            $output->writeln(sprintf(
                '<comment>[%d] From: %s | Subject: %s</comment>',
                $i + 1,
                $msg->getCustomerIdentifier(),
                $msg->getReplyTo()['subject'] ?? '(no subject)'
            ));

            if ($dryRun) {
                $output->writeln('   [dry-run] Skipping processing.');
                continue;
            }

            try {
                $response = $this->processor->process($msg);
                $output->writeln('   <info>Processed. Response: ' . mb_substr($response['text'] ?? '', 0, 120) . '...</info>');
            } catch (\Throwable $e) {
                $output->writeln('<error>   Error: ' . $e->getMessage() . '</error>');
            }
        }

        return Command::SUCCESS;
    }
}
