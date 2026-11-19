<?php

namespace Tests\Feature\Tickets;

use App\Models\FormCode;
use App\Models\Status;
use App\Models\Ticket;
use App\Services\JobOrderFormBuilder;
use App\Services\JobOrderHeader;
use App\Services\JobOrderPdfBuilder;
use Database\Factories\TicketFactory;
use GdImage;
use Illuminate\Support\Facades\View;
use Tests\ApiTestCase;
use ZipArchive;

/**
 * The control box in the printed header shows the form code and effectivity date from the
 * form_codes table: the filled job order (PDF and Excel) the "job_order" row, the blank job order
 * PDF the "job_order_blank" row. The text is drawn on every download, so an edit shows at once.
 * The survey page's own form code picture is separate and must not change.
 */
class JobOrderHeaderTest extends ApiTestCase
{
    /** sha256 of the template's header picture: the letterhead with an empty control box. */
    private const EMPTY_HEADER_SHA = '2fd615d96e37693dd07c0a7c6889ddc1e886fe80a007fbf1e9615bbd9d6e4d8e';

    /** sha256 of the survey form code picture, which this feature leaves alone. */
    private const SURVEY_CODE_SHA = '606d14ff0a9f7c3205d95d898497c82e53976b867c7edaff79cb8fd432c47e86';

    private function templatePart(string $part): string
    {
        $zip = new ZipArchive;
        $this->assertTrue($zip->open(resource_path(JobOrderFormBuilder::TEMPLATE)) === true);
        $bytes = $zip->getFromName($part);
        $zip->close();
        $this->assertNotFalse($bytes);

        return $bytes;
    }

    private function image(string $png): GdImage
    {
        $image = imagecreatefromstring($png);
        $this->assertInstanceOf(GdImage::class, $image);

        return $image;
    }

    private function isDark(GdImage $image, int $x, int $y): bool
    {
        $c = imagecolorat($image, $x, $y);

        return (($c >> 16) & 0xFF) < 128 && (($c >> 8) & 0xFF) < 128 && ($c & 0xFF) < 128;
    }

    /** Dark pixels inside the box (the 1 px border excluded), as x/y bounds. */
    private function textBounds(GdImage $image): ?array
    {
        $bounds = null;
        for ($y = 44; $y <= 90; $y++) {
            for ($x = 739; $x <= 850; $x++) {
                if ($this->isDark($image, $x, $y)) {
                    $bounds ??= ['x0' => $x, 'x1' => $x, 'y0' => $y, 'y1' => $y];
                    $bounds = ['x0' => min($bounds['x0'], $x), 'x1' => max($bounds['x1'], $x), 'y0' => min($bounds['y0'], $y), 'y1' => max($bounds['y1'], $y)];
                }
            }
        }

        return $bounds;
    }

    private function setCode(string $key, string $code, string $date): void
    {
        FormCode::query()->where('key', $key)->update(['document_code' => $code, 'effectivity_date' => $date]);
    }

    /**
     * The picture the PDF is rendered with for a ticket.
     */
    private function pdfHeader(Ticket $ticket): string
    {
        $uri = '';
        View::composer('job-order.pdf', function ($view) use (&$uri) {
            $uri = $view->getData()['img']['header_job_order'];
        });

        $path = app(JobOrderPdfBuilder::class)->build($ticket);
        $this->assertStringStartsWith('%PDF', (string) file_get_contents($path));
        @unlink($path);

        $this->assertStringStartsWith('data:image/png;base64,', $uri);

        return (string) base64_decode(substr($uri, strlen('data:image/png;base64,')));
    }

    private function excelHeader(Ticket $ticket): string
    {
        $path = app(JobOrderFormBuilder::class)->build($ticket);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path) === true);
        $bytes = $zip->getFromName(JobOrderFormBuilder::HEADER_PART);
        $zip->close();
        @unlink($path);
        $this->assertNotFalse($bytes);

        return $bytes;
    }

    private function filledTicket(): Ticket
    {
        return TicketFactory::new()->forDepartment($this->itDepartment())->open()->create(['ticket_number' => '10-003-2026']);
    }

    private function blankTicket(): Ticket
    {
        $this->actingAsUser($this->itUser());
        $id = $this->postJson('/api/tickets/blank-job-order')->assertCreated()->json('result.ticket.id');

        return Ticket::findOrFail($id);
    }

    public function test_the_template_keeps_only_the_empty_box_and_the_survey_code_is_unchanged(): void
    {
        $this->assertSame(self::EMPTY_HEADER_SHA, hash('sha256', $this->templatePart(JobOrderFormBuilder::HEADER_PART)));
        $this->assertSame(self::SURVEY_CODE_SHA, hash('sha256', $this->templatePart('xl/media/image7.png')));
        $this->assertNull($this->textBounds($this->image($this->templatePart(JobOrderFormBuilder::HEADER_PART))));
    }

    public function test_the_header_draws_both_lines_inside_the_box_and_changes_nothing_else(): void
    {
        $template = $this->image($this->templatePart(JobOrderFormBuilder::HEADER_PART));
        $header = $this->image(app(JobOrderHeader::class)->png(FormCode::JOB_ORDER));

        $this->assertSame([859, 100], [imagesx($header), imagesy($header)]);

        for ($y = 0; $y < 100; $y++) {
            for ($x = 0; $x < 859; $x++) {
                $inside = $x >= 739 && $x <= 850 && $y >= 44 && $y <= 90;
                if (! $inside) {
                    $this->assertSame(imagecolorat($template, $x, $y), imagecolorat($header, $x, $y), "pixel {$x},{$y} changed");
                }
            }
        }

        $bounds = $this->textBounds($header);
        $this->assertNotNull($bounds);
        $this->assertGreaterThanOrEqual(741, $bounds['x0']);
        $this->assertLessThanOrEqual(848, $bounds['x1']);
        $this->assertGreaterThanOrEqual(15, $bounds['y1'] - $bounds['y0'], 'two lines of text should be drawn');
    }

    public function test_a_short_code_is_drawn_larger_than_the_long_one_and_a_very_long_one_still_fits(): void
    {
        $header = app(JobOrderHeader::class);
        $capRows = function (GdImage $image): int {
            $rows = 0;
            for ($y = 44; $y <= 66; $y++) {
                for ($x = 739; $x <= 850; $x++) {
                    if ($this->isDark($image, $x, $y)) {
                        $rows++;
                        break;
                    }
                }
            }

            return $rows;
        };

        $short = $this->image($header->draw('FORM 7', 'Effectivity Date: (TBA)'));
        $long = $this->image($header->draw('GBTRGHSC-ICTS-04-0003-0000', 'Effectivity Date: (TBA)'));
        $veryLong = $this->image($header->draw(str_repeat('W', 60), 'Effectivity Date: '.str_repeat('M', 40)));

        $this->assertGreaterThan($capRows($long), $capRows($short));
        $this->assertLessThanOrEqual(848, $this->textBounds($long)['x1']);
        $this->assertLessThanOrEqual(848, $this->textBounds($veryLong)['x1']);
    }

    public function test_the_picture_changes_when_the_row_changes_and_nothing_is_cached(): void
    {
        $header = app(JobOrderHeader::class);
        $before = $header->png(FormCode::JOB_ORDER);

        $this->assertSame($before, $header->png(FormCode::JOB_ORDER));

        $this->setCode(FormCode::JOB_ORDER, 'GBTRGHSC-ICTS-04-0003-0001', 'December 1, 2026');

        $after = $header->png(FormCode::JOB_ORDER);
        $this->assertNotSame($before, $after);
        $this->assertNotSame($after, $header->png(FormCode::JOB_ORDER_BLANK));
    }

    public function test_the_filled_job_order_pdf_and_excel_use_the_job_order_row_and_follow_an_edit(): void
    {
        $ticket = $this->filledTicket();
        $generator = app(JobOrderHeader::class);

        $this->assertSame($generator->png(FormCode::JOB_ORDER), $this->pdfHeader($ticket));
        $this->assertSame($generator->png(FormCode::JOB_ORDER), $this->excelHeader($ticket));
        $firstExcel = $this->excelHeader($ticket);

        $this->setCode(FormCode::JOB_ORDER, 'GBTRGHSC-ICTS-04-0003-0001', 'December 1, 2026');
        $this->setCode(FormCode::JOB_ORDER_BLANK, 'SOMETHING-ELSE', '(TBA)');

        $edited = $generator->png(FormCode::JOB_ORDER);
        $this->assertSame($edited, $this->pdfHeader($ticket));
        $this->assertSame($edited, $this->excelHeader($ticket));
        $this->assertNotSame($firstExcel, $this->excelHeader($ticket));
    }

    public function test_the_blank_job_order_pdf_uses_the_blank_row_and_follows_an_edit(): void
    {
        $ticket = $this->blankTicket();
        $generator = app(JobOrderHeader::class);

        $this->assertSame($generator->png(FormCode::JOB_ORDER_BLANK), $this->pdfHeader($ticket));
        $this->assertNotSame($generator->png(FormCode::JOB_ORDER), $this->pdfHeader($ticket));

        $this->setCode(FormCode::JOB_ORDER_BLANK, 'GBTRGHSC-ICTS-04-0004-0001', 'January 5, 2027');

        $this->assertSame($generator->png(FormCode::JOB_ORDER_BLANK), $this->pdfHeader($ticket));
    }

    public function test_a_blank_form_that_has_been_filled_in_goes_back_to_the_filled_header(): void
    {
        $ticket = $this->blankTicket();
        $ticket->update(['status_id' => Status::where('label', 'In-progress')->value('id')]);

        $this->assertSame(app(JobOrderHeader::class)->png(FormCode::JOB_ORDER), $this->pdfHeader($ticket->fresh()));
    }

    public function test_a_missing_row_falls_back_to_the_default_code(): void
    {
        $with = app(JobOrderHeader::class)->png(FormCode::JOB_ORDER);
        FormCode::query()->where('key', FormCode::JOB_ORDER)->delete();

        $this->assertSame($with, app(JobOrderHeader::class)->png(FormCode::JOB_ORDER));
    }

    public function test_the_download_endpoints_still_work_with_the_drawn_header(): void
    {
        $this->actingAsUser($this->itUser());
        $ticket = $this->openItTicket();
        $this->actingAsUser($this->itUser());

        $this->get("/api/tickets/{$ticket->id}/job-order")->assertOk();
        $this->get("/api/tickets/{$ticket->id}/job-order?format=pdf")->assertOk();
    }
}
