<?php

namespace App\Services;

use App\Models\FormCode;
use RuntimeException;
use ZipArchive;

/**
 * Draws the control box of the Job Order header: the form's ISO code and its effectivity date.
 *
 * The header picture in the Excel template keeps only the empty box. The two lines of text come
 * from the form_codes table and are drawn here with GD on every call, so an edit in Admin shows on
 * the very next download (nothing is cached). Both the Excel workbook and the PDF use the PNG this
 * returns. The text is Arial Bold, left aligned, starting at about 9 px and shrinking only as far
 * as needed for the longer line to fit the box.
 */
class JobOrderHeader
{
    /** Inside of the box in the 859 x 100 header picture (the 1 px border is not touched). */
    private const BOX_X = 739;

    private const BOX_Y = 44;

    private const BOX_WIDTH = 112;

    private const BOX_HEIGHT = 47;

    /** Text starts this far from the left border and stops this far from the right one, in pixels. */
    private const PAD_LEFT = 5;

    private const PAD_RIGHT = 4;

    /** Old text size, line pitch and the supersampling factor used to keep the edges smooth. */
    private const START_SIZE_PX = 9.0;

    private const LINE_PITCH_PX = 12.5;

    private const SCALE = 8;

    /** Capital letters are this fraction of the font size in Arial. */
    private const CAP_RATIO = 0.716;

    /**
     * Arial Bold first; the others are metric-compatible or fallback faces for servers without it.
     *
     * @var list<string>
     */
    private const FONTS = [
        'C:\\Windows\\Fonts\\arialbd.ttf',
        '/usr/share/fonts/truetype/msttcorefonts/Arial_Bold.ttf',
        '/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf',
        '/usr/share/fonts/liberation/LiberationSans-Bold.ttf',
    ];

    /**
     * The header picture (PNG bytes) with the current form code and effectivity date of a form.
     *
     * @param  string  $formKey  FormCode::JOB_ORDER or FormCode::JOB_ORDER_BLANK
     */
    public function png(string $formKey): string
    {
        if (! function_exists('imagettftext')) {
            throw new RuntimeException('The PHP gd extension with FreeType support is needed to draw the job order header.');
        }

        $row = FormCode::query()->where('key', $formKey)->first();
        $defaults = FormCode::DEFAULTS[$formKey] ?? FormCode::DEFAULTS[FormCode::JOB_ORDER];

        return $this->draw(
            (string) ($row?->document_code ?? $defaults['document_code']),
            'Effectivity Date: '.(string) ($row?->effectivity_date ?? $defaults['effectivity_date']),
        );
    }

    /**
     * Two lines of text inside the empty box of the template's header picture.
     */
    public function draw(string $line1, string $line2): string
    {
        $base = imagecreatefromstring($this->emptyHeader());

        if ($base === false) {
            throw new RuntimeException('The job order header picture could not be read.');
        }

        $font = $this->font();
        $scale = self::SCALE;
        $layer = imagecreatetruecolor(self::BOX_WIDTH * $scale, self::BOX_HEIGHT * $scale);
        imagefill($layer, 0, 0, imagecolorallocate($layer, 255, 255, 255));
        $black = imagecolorallocate($layer, 0, 0, 0);

        $size = $this->fittingSize($font, [$line1, $line2]);
        $cap = $this->capHeight($font, $size);
        $pitch = self::LINE_PITCH_PX * $scale;
        $top = (self::BOX_HEIGHT * $scale - ($cap + $pitch)) / 2;
        $x = self::PAD_LEFT * $scale;

        imagettftext($layer, $size, 0, $x, (int) round($top + $cap), $black, $font, $line1);
        imagettftext($layer, $size, 0, $x, (int) round($top + $cap + $pitch), $black, $font, $line2);

        $small = imagecreatetruecolor(self::BOX_WIDTH, self::BOX_HEIGHT);
        imagecopyresampled($small, $layer, 0, 0, 0, 0, self::BOX_WIDTH, self::BOX_HEIGHT, self::BOX_WIDTH * $scale, self::BOX_HEIGHT * $scale);

        imagealphablending($base, true);
        imagecopy($base, $small, self::BOX_X, self::BOX_Y, 0, 0, self::BOX_WIDTH, self::BOX_HEIGHT);
        imagesavealpha($base, true);

        ob_start();
        imagepng($base);
        $png = (string) ob_get_clean();

        return $png;
    }

    /**
     * Font size for GD (at the supersampled scale): the old 9 px size, or smaller when the longest
     * line would not fit between the paddings.
     *
     * @param  list<string>  $lines
     */
    private function fittingSize(string $font, array $lines): float
    {
        $maxWidth = (self::BOX_WIDTH - self::PAD_LEFT - self::PAD_RIGHT) * self::SCALE;
        $size = $this->sizeForCapHeight($font, self::START_SIZE_PX * self::CAP_RATIO * self::SCALE);

        while ($size > 1 && max(array_map(fn (string $line) => $this->textWidth($font, $size, $line), $lines)) > $maxWidth) {
            $size = round($size - 0.25, 2);
        }

        return $size;
    }

    private function textWidth(string $font, float $size, string $text): float
    {
        $box = imagettfbbox($size, 0, $font, $text);

        return (float) abs($box[2] - $box[0]);
    }

    private function capHeight(string $font, float $size): float
    {
        $box = imagettfbbox($size, 0, $font, 'H');

        return (float) abs($box[7] - $box[1]);
    }

    /**
     * GD sizes are not pixels, so find the size whose capital letters are $cap pixels tall.
     */
    private function sizeForCapHeight(string $font, float $cap): float
    {
        $reference = $this->capHeight($font, 100.0);

        return $reference > 0 ? round(100.0 * $cap / $reference, 2) : 20.0;
    }

    private function font(): string
    {
        foreach (self::FONTS as $path) {
            if (is_file($path)) {
                return $path;
            }
        }

        // Always present: dompdf ships it, and it is the face the PDF text itself uses.
        $dejavu = base_path('vendor/dompdf/dompdf/lib/fonts/DejaVuSans-Bold.ttf');

        if (is_file($dejavu)) {
            return $dejavu;
        }

        throw new RuntimeException('No bold font was found to draw the job order header.');
    }

    /**
     * The template's header picture, which holds the letterhead and the empty box.
     */
    private function emptyHeader(): string
    {
        $zip = new ZipArchive;

        if ($zip->open(resource_path(JobOrderFormBuilder::TEMPLATE)) !== true) {
            throw new RuntimeException('The job order template could not be opened.');
        }

        $bytes = $zip->getFromName(JobOrderFormBuilder::HEADER_PART);
        $zip->close();

        if ($bytes === false) {
            throw new RuntimeException('The job order template has no header picture.');
        }

        return $bytes;
    }
}
