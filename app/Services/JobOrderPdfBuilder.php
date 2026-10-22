<?php

namespace App\Services;

use App\Models\Ticket;
use Dompdf\Dompdf;
use Dompdf\Options;
use RuntimeException;
use ZipArchive;

/**
 * Renders the Job Order form as a two-page A4 PDF from an HTML replica of the
 * Excel template. The letterhead and smiley pictures are read from the
 * template itself, so they are always the originals.
 */
class JobOrderPdfBuilder
{
    /** @var array<string, string> picture key => template media part */
    private const IMAGES = [
        'header_job_order' => 'xl/media/image1.png',
        'header_survey' => 'xl/media/image8.jpeg',
        'form_code_survey' => 'xl/media/image7.png',
    ];

    /** Smiley pictures, from Strongly Agree to Strongly Disagree. */
    private const SMILEYS = [
        'xl/media/image2.png',
        'xl/media/image3.png',
        'xl/media/image4.png',
        'xl/media/image5.png',
        'xl/media/image6.png',
    ];

    /**
     * Builds the PDF and returns the path of a temporary file.
     */
    public function build(Ticket $ticket): string
    {
        $html = view('job-order.pdf', [
            'd' => JobOrderData::fromTicket($ticket),
            'img' => $this->images(),
        ])->render();

        $cache = storage_path('framework/cache/dompdf');

        if (! is_dir($cache) && ! @mkdir($cache, 0775, true) && ! is_dir($cache)) {
            throw new RuntimeException('The PDF font cache directory could not be created.');
        }

        $options = new Options;
        $options->setIsRemoteEnabled(false);
        $options->setIsPhpEnabled(false);
        $options->setDefaultFont('DejaVu Sans');
        $options->setFontCache($cache);
        $options->setTempDir($cache);
        $options->setDpi(96);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        $path = tempnam(sys_get_temp_dir(), 'jo');

        if (file_put_contents($path, $dompdf->output()) === false) {
            @unlink($path);

            throw new RuntimeException('The job order PDF could not be written.');
        }

        return $path;
    }

    public function filename(Ticket $ticket): string
    {
        return JobOrderData::filename($ticket, 'pdf');
    }

    /**
     * @return array{header_job_order: string, header_survey: string, form_code_survey: string, smiley: list<string>}
     */
    private function images(): array
    {
        $template = resource_path(JobOrderFormBuilder::TEMPLATE);
        $zip = new ZipArchive;

        if (! is_file($template) || $zip->open($template) !== true) {
            throw new RuntimeException('The job order template could not be opened.');
        }

        try {
            $images = array_map(fn (string $part) => $this->dataUri($zip, $part), self::IMAGES);
            $images['smiley'] = array_map(fn (string $part) => $this->dataUri($zip, $part), self::SMILEYS);
        } finally {
            $zip->close();
        }

        return $images;
    }

    private function dataUri(ZipArchive $zip, string $part): string
    {
        $bytes = $zip->getFromName($part);

        if ($bytes === false) {
            throw new RuntimeException("The job order template is missing {$part}.");
        }

        $mime = str_ends_with($part, '.png') ? 'image/png' : 'image/jpeg';

        return 'data:'.$mime.';base64,'.base64_encode($bytes);
    }
}
