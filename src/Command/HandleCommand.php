<?php

declare(strict_types=1);

namespace Netzarbeiter\Shopware\ImageFiller\Command;

use Doctrine\DBAL\Connection;
use Shopware\Core\Content\Media\File\FileFetcher;
use Shopware\Core\Content\Media\File\FileLoader;
use Shopware\Core\Content\Media\File\FileSaver;
use Shopware\Core\Content\Media\MediaEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Fill missing images
 */
class HandleCommand extends \Symfony\Component\Console\Command\Command
{
    protected const OPTION_DRY_RUN = 'dry-run';
    protected const OPTION_LIMIT = 'limit';

    /**
     * @inerhitDoc
     */
    protected static $defaultName = 'netzarbeiter:images:fill';

    /**
     * @inerhitDoc
     */
    protected static $defaultDescription = 'Fill missing images';

    /**
     * Context
     *
     * @var Context
     */
    protected Context $context;

    /**
     * Style for input/output
     *
     * @var SymfonyStyle
     */
    protected SymfonyStyle $io;

    /**
     * HandleCommand constructor.
     *
     * @param Connection $connection
     * @param EntityRepository $mediaRepository
     * @param FileLoader $fileLoader
     * @param FileFetcher $fileFetcher
     * @param FileSaver $fileSaver
     */
    public function __construct(
        protected Connection $connection,
        protected EntityRepository $mediaRepository,
        protected FileLoader $fileLoader,
        protected FileFetcher $fileFetcher,
        protected FileSaver $fileSaver
    ) {
        parent::__construct();

        // Create context.
        $this->context = Context::createDefaultContext();
        $this->context->addState(
            \Shopware\Core\Framework\DataAbstractionLayer\Indexing\EntityIndexerRegistry::DISABLE_INDEXING
        );
    }

    /**
     * @inerhitDoc
     */
    protected function configure(): void
    {
        $this
            ->addOption(
                self::OPTION_LIMIT,
                null,
                InputOption::VALUE_REQUIRED,
                'Limit number of images to check'
            )
            ->addOption(
                self::OPTION_DRY_RUN,
                null,
                InputOption::VALUE_NONE,
                'Dry run, only show what would be done'
            );
    }

    /**
     * @inerhitDoc
     */
    protected function initialize(InputInterface $input, OutputInterface $output): void
    {
        $this->io = new SymfonyStyle($input, $output);
    }

    /**
     * @inerhitDoc
     */
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        // Print plugin title.
        $this->io->title(sprintf('%s (%s)', $this->getDescription(), $this->getName()));

        // Fetch all ids of images (raw to not use too much memory).
        $limit = (int)($input->getOption(self::OPTION_LIMIT) ?? 0);
        $mimeTypes = [
            'image/jpeg',
            'image/png',
            'image/webp',
        ];
        $sql = 'SELECT media.id FROM media WHERE media.mime_type IN (:mimeTypes)';
        if ($limit > 0) {
            $sql .= ' LIMIT ' . $limit;
        }
        $ids = $this->connection->fetchFirstColumn(
            $sql,
            ['mimeTypes' => $mimeTypes],
            ['mimeTypes' => Connection::PARAM_STR_ARRAY]
        );

        // Convert ids to uuids.
        $ids = array_map(
            static fn(string $id): string => \Shopware\Core\Framework\Uuid\Uuid::fromBytesToHex($id),
            $ids
        );

        // Now walk over all ids in chunks and and check if the image exists.
        $chunkSize = 100;
        $chunks = array_chunk($ids, $chunkSize);
        $this->io->progressStart(count($ids));
        $stats = [
            'total' => count($ids),
            'updated' => 0,
        ];
        foreach ($chunks as $ids) {
            // Fetch media items.
            $criteria = new Criteria($ids);
            $images = $this->mediaRepository->search($criteria, $this->context);

            // Walk over images and handle them.
            foreach ($images as $image) {
                if ($this->checkImage($image, $input->getOption(self::OPTION_DRY_RUN))) {
                    $stats['updated']++;
                }
            }

            // Advance progress bar.
            $this->io->progressAdvance(count($ids));
        }
        $this->io->progressFinish();

        // Print stats.
        $this->io->success(sprintf('Checked %d images, updated %d of them', $stats['total'], $stats['updated']));

        return self::SUCCESS;
    }

    /**
     * Check if the image exists and if not, try to create it.
     *
     * @param MediaEntity $image
     * @param bool $dryRun
     * @return bool
     */
    protected function checkImage(MediaEntity $image, bool $dryRun = false): bool
    {
        // Try to load the file.
        $data = $this->fileLoader->loadMediaFile($image->getId(), $this->context);
        if (!empty($data)) {
            return false;
        }

        // If dry-run is enabled, we're done.
        if ($dryRun) {
            $this->io->warning(sprintf('Image "%s" does not exist, would be updated now', $image->getFileName()));
            return false;
        }

        // Get and check dimensions.
        $dimensions = $this->getDimensions($image);
        if (empty($dimensions)) {
            $this->io->warning(sprintf('Image "%s" has no dimensions', $image->getFileName()));
            return false;
        }

        // Fetch file from external service.
        $url = sprintf(
            'https://placehold.co/%dx%d/%s',
            $dimensions['width'],
            $dimensions['height'],
            $this->getExtension($image)
        );
        $request = new \Symfony\Component\HttpFoundation\Request();
        $request->request->set('url', $url);
        $data = $this->fileFetcher->fetchFileFromURL($request, $image->getFileName());
        if (empty($data)) {
            $this->io->warning(sprintf('Image "%s" could not be fetched from url "%s"', $image->getFileName(), $url));
            return false;
        }

        // Save download image.
        $this->fileSaver->persistFileToMedia(
            $data,
            $image->getFileName(),
            $image->getId(),
            $this->context
        );

        return true;
    }

    /**
     * Get image dimensions.
     *
     * @param MediaEntity $image
     * @return array|null
     */
    protected function getDimensions(MediaEntity $image): ?array
    {
        $metadata = $image->getMetaData();
        if (empty($metadata)) {
            return null;
        }

        $width = $metadata['width'] ?? null;
        $height = $metadata['height'] ?? null;
        if (empty($width) || empty($height)) {
            return null;
        }

        return [$width, $height];
    }

    /**
     * Get file extension.
     *
     * @param MediaEntity $image
     * @return string
     */
    protected function getExtension(MediaEntity $image): string
    {
        switch ($image->getMimeType()) {
            case 'image/jpeg':
                return $image->getFileExtension() ?? 'jpg';

            case 'image/png':
                return 'png';

            case 'image/webp':
                return 'webp';
        }

        throw new \RuntimeException(sprintf('Unknown mime type "%s"', $image->getMimeType()));
    }
}
