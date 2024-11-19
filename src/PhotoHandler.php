<?php

declare(strict_types=1);

namespace Nelwhix\PhotoSharingPhpArch;

use Aws\Rekognition\RekognitionClient;
use Aws\S3\S3Client;
use Bref\Context\Context;
use Bref\Event\S3\S3Event;
use Bref\Event\S3\S3Handler;
use Bref\Logger\StderrLogger;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use Throwable;

class PhotoHandler extends S3Handler
{
    public function handleS3(S3Event $event, Context $context): void
    {
        $log = new StderrLogger();

        try {
            $bucket = $event->getRecords()[0]->getBucket()->getName();
            $fileName = $event->getRecords()[0]->getObject()->getKey();
            $s3Client = new S3Client([
                'region'  => 'eu-central-1',
                'version' => 'latest',
            ]);

            $uploadedFile = $s3Client->getObject([
                'Bucket' => $bucket,
                'Key'    => $fileName,
            ]);

            $fileContent = $uploadedFile['Body']->getContents();
            $imageManager = new ImageManager(new Driver());

            $thumbnailImg = $imageManager->read($fileContent)->cover(width: 150, height: 150)->encode();
            $s3Client->putObject([
                'Bucket'      => $_ENV['S3_OUTPUT_BUCKET'],
                'Key'         => 'thumbnails/' . $fileName,
                'Body'        => $thumbnailImg->toFilePointer(),
            ]);

            $previewImg = $imageManager->read($fileContent)->cover(width: 800, height: 600)->encode();
            $s3Client->putObject([
                'Bucket'      => $_ENV['S3_OUTPUT_BUCKET'],
                'Key'         => 'previews/' . $fileName,
                'Body'        => $previewImg->toFilePointer(),
            ]);

            $waterMarkRes = $s3Client->getObject([
                'Bucket' => $_ENV['S3_OUTPUT_BUCKET'],
                'Key'    => 'watermark.png',
            ]);

            $watermark = $waterMarkRes['Body']->getContents();

            $watermarkedImage = $imageManager->read($fileContent)->place(
                $watermark,
                'bottom-right',
                10,
                10,
                25
            )->encode();
            $s3Client->putObject([
                'Bucket'      => $_ENV['S3_OUTPUT_BUCKET'],
                'Key'         => 'images/' . $fileName,
                'Body'        => $watermarkedImage->toFilePointer(),
            ]);

            $rekClient = new RekognitionClient([
                'region'  => 'eu-central-1',
                'version'   => 'latest',
            ]);

            $uploadedFile = $rekClient->detectModerationLabels([
                'Image' => [
                    'S3Object' => [
                        'Bucket' => $bucket,
                        'Name'   => $fileName,
                    ],
                ],
                'MinConfidence' => 50,
            ]);
            if (! empty($uploadedFile['ModerationLabels'])) {
               $blurredImg = $imageManager->read($fileContent)->blur(50);
                $s3Client->putObject([
                    'Bucket'      => $_ENV['S3_OUTPUT_BUCKET'],
                    'Key'         => 'images/' . $fileName,
                    'Body'        => $blurredImg,
                ]);
            }

        } catch (Throwable $e) {
            $log->error("An error occurred: " . $e->getMessage() . ' ' . $e->getTraceAsString());
        }

    }
}