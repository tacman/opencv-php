<?php
declare(strict_types=1);

namespace OpenCvExamples;

use CV\Mat;

use const CV\{CV_32F, FaceRecognizerSF_FR_COSINE, FONT_HERSHEY_SIMPLEX, LINE_AA};

/**
 * Ported from php-opencv/php-opencv-examples (detect_face_by_facedetectoryn.php and
 * recognize_face_by_facerecognizersf.php), written for php-opencv on OpenCV 4.
 *
 * Both need an OpenCV built with the dnn module, which bin/build-opencv-lean.sh
 * leaves out, and models from https://github.com/opencv/opencv_zoo in examples/models/:
 * face_detection_yunet_2023mar.onnx (MIT; OpenCV 5 cannot load the 2022mar file)
 * and face_recognition_sface_2021dec.onnx (Apache-2.0).
 */
#[Group('Faces', order: 5)]
final class Faces
{
    private const NEEDS = 'An OpenCV built with the dnn module, the YuNet and SFace models, and a photograph of your own.';

    #[Example(
        title: 'Find faces with YuNet',
        description: 'FaceDetectorYN returns a box, five landmarks (eyes, nose, mouth corners) and a confidence for each face.',
        order: 1,
        needs: self::NEEDS,
    )]
    public function detect(Mat $photo): Mat
    {
        $detector = \CV\FaceDetectorYN::create(__DIR__ . '/../models/face_detection_yunet_2023mar.onnx', '', [$photo->cols(), $photo->rows()]);
        [, $faces] = $detector->detect($photo);

        $result = $photo->clone();
        foreach ($faces->empty() ? [] : $faces->toArray() as $face) {
            [$x, $y, $w, $h] = array_map('intval', array_slice($face, 0, 4));
            \CV\rectangle($result, [$x, $y, $w, $h], [0, 0, 255], 3);
            for ($i = 4; $i < 14; $i += 2) {
                \CV\circle($result, [(int) $face[$i], (int) $face[$i + 1]], 3, [0, 255, 0], -1, LINE_AA);
            }
            \CV\putText($result, sprintf('%.2f', $face[14]), [$x, $y - 8], FONT_HERSHEY_SIMPLEX, 0.6, [0, 0, 255], 2, LINE_AA);
        }

        return $result;
    }

    #[Example(
        title: 'Match faces with SFace',
        description: 'FaceRecognizerSF describes each face as 128 numbers. Faces whose numbers are close are the same person; each box is labelled with a letter per person.',
        order: 2,
        needs: self::NEEDS,
    )]
    public function match(Mat $photo): Mat
    {
        $detector = \CV\FaceDetectorYN::create(__DIR__ . '/../models/face_detection_yunet_2023mar.onnx', '', [$photo->cols(), $photo->rows()]);
        $recognizer = \CV\FaceRecognizerSF::create(__DIR__ . '/../models/face_recognition_sface_2021dec.onnx', '');
        [, $faces] = $detector->detect($photo);
        $faces = $faces->empty() ? [] : $faces->toArray();

        // Describe each face, turned upright on its landmarks first.  The row must be 32-bit floats.
        $features = [];
        foreach ($faces as $face) {
            $aligned = $recognizer->alignCrop($photo, Mat::fromArray([$face], CV_32F));
            $features[] = $recognizer->feature($aligned)->clone();
        }

        // 0.363 is the cosine score OpenCV documents as "same person".
        $person = [];
        foreach ($features as $i => $feature) {
            for ($j = 0; $j < $i && !isset($person[$i]); $j++) {
                if ($recognizer->match($feature, $features[$j], FaceRecognizerSF_FR_COSINE) >= 0.363) {
                    $person[$i] = $person[$j];
                }
            }
            $person[$i] ??= count(array_unique($person));
        }

        $result = $photo->clone();
        foreach ($faces as $i => $face) {
            [$x, $y, $w, $h] = array_map('intval', array_slice($face, 0, 4));
            \CV\rectangle($result, [$x, $y, $w, $h], [0, 0, 255], 3);
            \CV\putText($result, 'person ' . chr(65 + $person[$i]), [$x, $y - 8], FONT_HERSHEY_SIMPLEX, 0.7, [0, 0, 255], 2, LINE_AA);
        }

        return $result;
    }
}
