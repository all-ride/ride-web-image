<?php

namespace ride\web\image;

use ride\library\dependency\DependencyInjector;
use ride\library\http\Header;
use ride\library\http\Response;
use ride\library\image\exception\ImageException;
use ride\library\image\ImageUrlGenerator as LibImageUrlGenerator;
use ride\library\system\file\browser\FileBrowser;
use ride\library\system\file\File;

/**
 * URL generator for images.
 */
class ImageUrlGenerator implements LibImageUrlGenerator {

    /**
     * Path in the public directory to cache processed images
     * @var string
     */
    const DEFAULT_PATH = 'cache/img';

    /**
     * Instance of the file browser
     * @var \ride\library\system\file\browser\FileBrowser
     */
    private $fileBrowser;

    /**
     * Instance of the dependency injector
     * @var \ride\library\dependency\DependencyInjector
     */
    private $dependencyInjector;

    /**
     * Base URL for the images
     * @var string
     */
    private $baseUrl;

    /**
     * Absolute path of the public directory
     * @var string
     */
    private $publicPath;

    /**
     * The path for the processed images
     * @var \ride\library\system\file\File
     */
    private $path;

    /**
     * Constructs a image URL generator
     * @param \ride\library\system\file\browser\FileBrowser $fileBrowser
     * @param string $path Relative path in the public directory to save the
     * processed images
     * @return null
     */
    public function __construct(FileBrowser $fileBrowser, DependencyInjector $dependencyInjector, $baseUrl, $path = null) {
        $this->fileBrowser = $fileBrowser;
        $this->dependencyInjector = $dependencyInjector;
        $this->baseUrl = $baseUrl;
        $this->publicPath = $fileBrowser->getPublicDirectory()->getAbsolutePath();

        $this->setPath($path);
    }

    /**
     * Sets the path to save the processed images
     * @param string $path Relative path in the public directory
     * @return null
     */
    public function setPath($path) {
        if ($path == null) {
            $path = self::DEFAULT_PATH;
        }

        $this->path = $this->fileBrowser->getPublicDirectory()->getChild($path);
    }

    /**
     * Gets the cache directory
     * @return \ride\library\system\file\File
     */
    public function getCacheDirectory() {
        return $this->path;
    }

    /**
     * Generates a URL for the provided image.
     * @param string|\ride\library\system\file\File $image Path or File instance to the image
     * @param string|array $transformations Name of a transformation or an array
     * with the name of the transformation as key and the options as value
     * @param array $options Options for the transformation (when name provided)
     * @return null
     */
    public function generateUrl($image, $transformations = null, array $options = null) {
        if (is_string($image) && strlen($image) > 7 && substr($image, 0, 7) == 'http://' || substr($image, 0, 8) == 'https://') {
            // image is a URL
            if (!$transformations) {
                return $image;
            } elseif (!is_array($transformations)) {
                $transformations = array($transformations => $options);
            }

            $file = $this->getCacheFile($image, $transformations);
            if (!$file->exists()) {
                $httpClient = $this->dependencyInjector->get('ride\\library\\http\\client\\Client');

                $response = $httpClient->get($image);
                if ($response->getStatusCode() != Response::STATUS_CODE_OK) {
                    $location = $response->getHeader(Header::HEADER_LOCATION);
                    if ($location) {
                        $response = $httpClient->get($location);
                    }
                }

                if ($response->getStatusCode() != Response::STATUS_CODE_OK) {
                    throw new ImageException('Could not generate URL for ' . $image . ': file not found');
                }

                $file->write($response->getBody());

                $this->applyTransformations($file, $transformations);
                $this->applyOptimization($file);
            }
        } else {
            // image is a local file
            $source = $this->lookupFile($image);

            $isPublicFile = strpos($source->getAbsolutePath(), $this->publicPath) === 0;
            if (!$transformations && $isPublicFile) {
                $file = $source;
            } else {
                if (!is_array($transformations)) {
                    $transformations = array($transformations => $options);
                }

                $file = $this->getCacheFile($source->getPath(), $transformations);
                if (!$file->exists() || $source->getModificationTime() > $file->getModificationTime()) {
                    $source->copy($file);

                    if ($transformations) {
                        $this->applyTransformations($file, $transformations);
                    }

                    $this->applyOptimization($file);
                }
            }
        }

        // make the resulting image relative to the public directory
        $image = str_replace($this->publicPath, '', $file->getAbsolutePath());

        // return the full URL
        return $this->baseUrl . $image;
    }

    /**
     * Applies the provided thumbnailer to the provided file
     * @param \ride\library\system\file\File $file File of the source image
     * @param array $transformations Array with the name of the transformation
     * as key and the options as value
     * @return null
     */
    protected function applyTransformations(File $file, array $transformations) {
        $imageFactory = $this->dependencyInjector->get('ride\\library\\image\\ImageFactory');

        $image = $imageFactory->createImage();
        $image->read($file);

        $transformedImage = $image;
        foreach ($transformations as $transformation => $options) {
            $transformation = $this->dependencyInjector->get('ride\\library\\image\\transformation\\Transformation', $transformation);
            $transformedImage = $transformation->transform($transformedImage, $options);
        }

        if ($transformedImage !== $image) {
            $transformedImage->write($file);
        }
    }

    /**
     * Applies image optimization to the provided file
     * @param \ride\library\system\file\File $file
     * @return null
     */
    protected function applyOptimization(File $file) {
        $optimizer = $this->dependencyInjector->get('ride\\library\\image\\optimizer\\Optimizer');
        $optimizer->optimize($file);
    }

    /**
     * Gets the cache file for the image source
     * @param string $source Source to get a cache file for (local path or URL)
     * @param array $transformations Array with the name of the transformation
     * as key and the options as value
     * @return \ride\library\system\file\File unique name for a source file, in
     * the cache directory, with the thumbnailer, width and height encoded into
     */
    protected function getCacheFile($source, array $transformations = null) {
        $hash = $source;
        if ($transformations) {
            foreach ($transformations as $transformation => $options) {
                $hash .= '-transformation=' . $transformation;
                if ($options) {
                    foreach ($options as $key => $value) {
                        $hash .= '-' . $key . '=' . $value;
                    }
                }
            }
        }

        $fileName = md5($hash);

        $positionSlash = strrpos($source, '/');
        if ($positionSlash !== false) {
            $name = substr($source, $positionSlash + 1);
        } else {
            $name = $source;
        }

        $fileName .= '-' . $name;

        return $this->path->getChild($fileName);
    }

    /**
     * Gets the local file for the provided file path
     * @param string $source Path of a local file
     * @return \ride\library\system\file\File
     * @throws \ride\library\image\exception\ImageException when the file could
     * not be found
     */
    protected function lookupFile($source) {
        if ($source instanceof File) {
            return $source;
        }

        // image is a local file
        $file = $this->fileBrowser->getFileSystem()->getFile($source);

        if ($file->isAbsolute()) {
            return $file;
        }

        // relative file, get the image from the file browser
        $file = $this->fileBrowser->getPublicFile($source);
        if ($file) {
            return $file;
        }

        $file = $this->fileBrowser->getFile($source);
        if ($file) {
            return $file;
        }

        throw new ImageException('Could not generate URL for ' . $source . ': file not found');
    }

}
