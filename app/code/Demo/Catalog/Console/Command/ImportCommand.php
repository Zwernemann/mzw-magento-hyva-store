<?php
declare(strict_types=1);

namespace Demo\Catalog\Console\Command;

use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Magento\Framework\Filesystem;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\ImportExport\Model\Import;
use Magento\ImportExport\Model\ImportFactory;
use Magento\ImportExport\Model\Import\Source\CsvFactory;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Thin wrapper around Magento's native product import engine.
 *
 * Usage: bin/magento demo:catalog:import var/import/lacke.csv
 *
 * Handles remote image download (http(s) URLs in image columns), auto-creates
 * missing categories from the "categories" column and links configurable/simple
 * products from the "configurable_variations" column.
 */
class ImportCommand extends Command
{
    public function __construct(
        private readonly State $appState,
        private readonly Filesystem $filesystem,
        private readonly ImportFactory $importFactory,
        private readonly CsvFactory $csvSourceFactory,
        ?string $name = null
    ) {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setName('demo:catalog:import')
            ->setDescription('Import demo catalog products from a CSV file (native import engine).')
            ->addArgument('file', InputArgument::REQUIRED, 'CSV file path relative to Magento root, e.g. var/import/lacke.csv')
            ->addOption('behavior', 'b', InputOption::VALUE_REQUIRED, 'Import behavior: append|replace|delete', Import::BEHAVIOR_APPEND);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->appState->setAreaCode(Area::AREA_ADMINHTML);
        } catch (\Exception $e) {
            // Area already set – fine.
        }

        $file = ltrim((string) $input->getArgument('file'), '/');
        $behavior = (string) $input->getOption('behavior');

        $root = $this->filesystem->getDirectoryWrite(DirectoryList::ROOT);
        if (!$root->isExist($file)) {
            $output->writeln("<error>File not found: {$file}</error>");
            return Command::FAILURE;
        }

        $output->writeln("<info>Importing {$file} (behavior: {$behavior})…</info>");

        /** @var Import $import */
        $import = $this->importFactory->create();
        $import->setData([
            'entity'              => 'catalog_product',
            'behavior'            => $behavior,
            'validation_strategy' => 'validation-skip-errors',
            'allowed_error_count' => 100000,
        ]);

        $source = $this->csvSourceFactory->create([
            'file'      => $file,
            'directory' => $root,
        ]);

        $validationResult = $import->validateSource($source);

        foreach ($import->getErrorAggregator()->getAllErrors() as $error) {
            $output->writeln('<comment>  ! ' . $error->getErrorMessage() . '</comment>');
        }

        if (!$validationResult) {
            $output->writeln('<error>Validation failed – no rows imported.</error>');
            $output->writeln(sprintf(
                'Processed: %d, invalid: %d',
                $import->getProcessedRowsCount(),
                $import->getErrorAggregator()->getInvalidRowsCount()
            ));
            return Command::FAILURE;
        }

        $import->importSource();
        $import->invalidateIndex();

        $output->writeln(sprintf(
            '<info>Done. Rows processed: %d, created/updated entities: %d, errors: %d</info>',
            $import->getProcessedRowsCount(),
            $import->getCreatedItemsCount() + $import->getUpdatedItemsCount(),
            $import->getErrorAggregator()->getErrorsCount()
        ));

        return Command::SUCCESS;
    }
}
