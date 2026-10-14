<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Cms\Controller\Adminhtml\Editor;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\Framework\UrlInterface;
use Magento\MediaStorage\Model\File\UploaderFactory;
use Magento\Store\Model\StoreManagerInterface;
use Zero\Cms\Model\Content\Target\Pool;

/**
 * An image for an image field: into pub/media/zero/cms/<year>/<month>/
 * through Magento's uploader (image types only, checked as images, unique
 * names). Answers with the media path the field stores and its URL.
 */
class Upload extends AbstractEditor implements HttpPostActionInterface
{
    private const ALLOWED = ['jpg', 'jpeg', 'png', 'webp', 'gif'];

    public function __construct(
        Context $context,
        Pool $targets,
        private readonly UploaderFactory $uploaderFactory,
        private readonly Filesystem $filesystem,
        private readonly StoreManagerInterface $storeManager,
        private readonly \Magento\Framework\Image\AdapterFactory $imageAdapterFactory
    ) {
        parent::__construct($context, $targets);
    }

    /**
     * @return \Magento\Framework\Controller\ResultInterface
     */
    public function execute()
    {
        try {
            $dir = 'zero/cms/' . date('Y/m');
            $uploader = $this->uploaderFactory->create(['fileId' => 'image']);
            $uploader->setAllowedExtensions(self::ALLOWED);
            $uploader->setAllowRenameFiles(true);
            $uploader->setFilesDispersion(false);
            // Checked as a real image (not just its extension).
            $uploader->addValidateCallback('zero_cms_image', $this->imageAdapterFactory->create(), 'validateUploadFile');
            $result = $uploader->save($this->filesystem->getDirectoryWrite(DirectoryList::MEDIA)->getAbsolutePath($dir));
        } catch (\Exception $e) {
            return $this->json(['error' => (string) __('The image could not be uploaded: %1', $e->getMessage())], 400);
        }
        $path = $dir . '/' . $result['file'];

        return $this->json([
            'path' => $path,
            'url' => rtrim($this->storeManager->getStore()->getBaseUrl(UrlInterface::URL_TYPE_MEDIA), '/') . '/' . $path,
        ]);
    }
}
