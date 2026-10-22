<?php

namespace App\Services;

use App\Models\Ticket;
use DOMDocument;
use DOMElement;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Fills the ICTS Job Request / Customer Satisfaction workbook for a ticket.
 *
 * The template is patched in place: only the text of the JO number, a set of
 * value cells and a few appended styles change. Every other part of the zip
 * (header pictures, smiley drawings, print setup) is carried over untouched.
 */
class JobOrderFormBuilder
{
    public const TEMPLATE = 'templates/jo-form.xlsx';

    private const NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    private const TEMPLATE_NUMBER = '10-100-26';

    private const SHEET_JOB_ORDER = 'xl/worksheets/sheet1.xml';

    private const SHEET_SURVEY = 'xl/worksheets/sheet2.xml';

    /** Box cell style: bold, centred, boxed. */
    private const TICK_STYLE = 17;

    /** @var array<string, string> priority key => box cell */
    private const PRIORITY_BOXES = ['ihomis' => 'M5', 'urgent' => 'M6', 'important' => 'M7', 'long_term' => 'M10'];

    /** @var array<string, string> category key => box cell */
    private const CATEGORY_BOXES = [
        'hardware' => 'K25',
        'software' => 'K26',
        'network' => 'K27',
        'photoshop' => 'K28',
        'audio' => 'K29',
        'tutorial' => 'K30',
        'others' => 'K31',
    ];

    /**
     * Builds the filled workbook and returns the path of a temporary file.
     */
    public function build(Ticket $ticket): string
    {
        $template = resource_path(self::TEMPLATE);

        if (! is_file($template)) {
            throw new RuntimeException('The job order template is missing.');
        }

        $data = JobOrderData::fromTicket($ticket);

        $path = tempnam(sys_get_temp_dir(), 'jo');
        $zip = new ZipArchive;

        try {
            copy($template, $path);

            if ($zip->open($path) !== true) {
                throw new RuntimeException('The job order template could not be opened.');
            }

            $zip->addFromString('xl/sharedStrings.xml', $this->patchJobOrderNumber($this->part($zip, 'xl/sharedStrings.xml'), $data->number));

            [$styles, $styleIds] = $this->patchStyles($this->part($zip, 'xl/styles.xml'));
            $zip->addFromString('xl/styles.xml', $styles);

            $zip->addFromString(self::SHEET_JOB_ORDER, $this->patchJobOrderSheet($this->part($zip, self::SHEET_JOB_ORDER), $this->cells($data, $styleIds)));
            $zip->addFromString(self::SHEET_SURVEY, $this->part($zip, self::SHEET_SURVEY, fn (string $xml) => str_replace(' tabSelected="1"', '', $xml)));
            $zip->addFromString('xl/workbook.xml', $this->part($zip, 'xl/workbook.xml', fn (string $xml) => str_replace('activeTab="1"', 'activeTab="0"', $xml)));

            $zip->close();
        } catch (Throwable $e) {
            @$zip->close();
            @unlink($path);

            throw $e;
        }

        return $path;
    }

    public function displayNumber(Ticket $ticket): string
    {
        return JobOrderData::displayNumber($ticket);
    }

    public function filename(Ticket $ticket): string
    {
        return JobOrderData::filename($ticket, 'xlsx');
    }

    private function part(ZipArchive $zip, string $name, ?callable $transform = null): string
    {
        $xml = $zip->getFromName($name);

        if ($xml === false) {
            throw new RuntimeException("The job order template is missing {$name}.");
        }

        return $transform ? $transform($xml) : $xml;
    }

    /**
     * Both pages show the same shared string, so one edit sets the number on each.
     */
    private function patchJobOrderNumber(string $xml, string $number): string
    {
        $doc = $this->load($xml);
        $matches = [];

        foreach ($doc->getElementsByTagNameNS(self::NS, 'si') as $item) {
            if (trim($item->textContent) === self::TEMPLATE_NUMBER) {
                $matches[] = $item;
            }
        }

        if (count($matches) !== 1) {
            throw new RuntimeException('The job order number cell was not found in the template.');
        }

        $text = $matches[0]->getElementsByTagNameNS(self::NS, 't')->item(0);
        $text->nodeValue = '';
        $text->appendChild($doc->createTextNode($number));

        return $doc->saveXML();
    }

    /**
     * Appends the underlined and left-aligned variants of existing styles.
     *
     * @return array{0: string, 1: array<string, int>}
     */
    private function patchStyles(string $xml): array
    {
        $doc = $this->load($xml);
        $fonts = $doc->getElementsByTagNameNS(self::NS, 'fonts')->item(0);
        $xfs = $doc->getElementsByTagNameNS(self::NS, 'cellXfs')->item(0);

        $addFont = function (int $baseId) use ($doc, $fonts): int {
            $font = $fonts->getElementsByTagNameNS(self::NS, 'font')->item($baseId)->cloneNode(true);
            $font->insertBefore($doc->createElementNS(self::NS, 'u'), $font->getElementsByTagNameNS(self::NS, 'sz')->item(0));
            $fonts->appendChild($font);
            $fonts->setAttribute('count', (string) $fonts->getElementsByTagNameNS(self::NS, 'font')->length);

            return $fonts->getElementsByTagNameNS(self::NS, 'font')->length - 1;
        };

        $addXf = function (int $baseId, ?int $fontId, ?string $horizontal) use ($doc, $xfs): int {
            $xf = $xfs->getElementsByTagNameNS(self::NS, 'xf')->item($baseId)->cloneNode(true);

            if ($fontId !== null) {
                $xf->setAttribute('fontId', (string) $fontId);
                $xf->setAttribute('applyFont', '1');
            }

            if ($horizontal !== null) {
                $alignment = $xf->getElementsByTagNameNS(self::NS, 'alignment')->item(0);

                if ($alignment === null) {
                    $alignment = $doc->createElementNS(self::NS, 'alignment');
                    $xf->appendChild($alignment);
                }

                $alignment->setAttribute('horizontal', $horizontal);
                $xf->setAttribute('applyAlignment', '1');
            }

            $xfs->appendChild($xf);
            $xfs->setAttribute('count', (string) $xfs->getElementsByTagNameNS(self::NS, 'xf')->length);

            return $xfs->getElementsByTagNameNS(self::NS, 'xf')->length - 1;
        };

        $ids = [
            'date_line' => $addXf(78, $addFont(19), null),
            'name_line' => $addXf(73, $addFont(10), null),
            'detail_first' => $addXf(76, null, 'left'),
            'detail_next' => $addXf(77, null, 'left'),
        ];

        return [$doc->saveXML(), $ids];
    }

    /**
     * @param  array<string, array{0: string, 1: ?int}>  $cells  ref => [text, style id]
     */
    private function patchJobOrderSheet(string $xml, array $cells): string
    {
        $doc = $this->load($xml);
        $index = [];

        foreach ($doc->getElementsByTagNameNS(self::NS, 'c') as $cell) {
            $index[$cell->getAttribute('r')] = $cell;
        }

        foreach ($cells as $ref => [$text, $style]) {
            $cell = $index[$ref] ?? throw new RuntimeException("Cell {$ref} was not found in the job order template.");

            $this->setText($doc, $cell, $text);

            if ($style !== null) {
                $cell->setAttribute('s', (string) $style);
            }
        }

        $view = $doc->getElementsByTagNameNS(self::NS, 'sheetView')->item(0);
        $view->setAttribute('tabSelected', '1');

        return $doc->saveXML();
    }

    private function setText(DOMDocument $doc, DOMElement $cell, string $text): void
    {
        while ($cell->firstChild) {
            $cell->removeChild($cell->firstChild);
        }

        $cell->setAttribute('t', 'inlineStr');

        $string = $doc->createElementNS(self::NS, 't');
        $string->setAttributeNS('http://www.w3.org/XML/1998/namespace', 'xml:space', 'preserve');
        $string->appendChild($doc->createTextNode($text));

        $inline = $doc->createElementNS(self::NS, 'is');
        $inline->appendChild($string);
        $cell->appendChild($inline);
    }

    /**
     * The value cells to write, in template order.
     *
     * @param  array<string, int>  $styleIds
     * @return array<string, array{0: string, 1: ?int}>
     */
    private function cells(JobOrderData $data, array $styleIds): array
    {
        $cells = [];

        $put = function (string $ref, ?string $text, ?int $style = null) use (&$cells): void {
            if ($text !== null) {
                $cells[$ref] = [$text, $style];
            }
        };

        $put('E5', $data->division);
        $put('E6', $data->equipment);

        foreach ($data->detailLines as $position => $line) {
            $put('C'.(13 + $position), $line, $position === 0 ? $styleIds['detail_first'] : $styleIds['detail_next']);
        }

        if ($data->priority !== null) {
            $cells[self::PRIORITY_BOXES[$data->priority]] = ['X', self::TICK_STYLE];
            $put($data->priority === 'long_term' ? 'N12' : 'N9', $data->expectedDate, $styleIds['date_line']);
        }

        $put('A18', $data->requesterName, $styleIds['name_line']);
        $put('C20', $data->requesterDesignation);
        $put('C21', $data->requestedDate);

        if ($data->category !== null) {
            $cells[self::CATEGORY_BOXES[$data->category]] = ['X', self::TICK_STYLE];
            $put('L31', $data->othersLabel === null ? null : 'Others (Pls. Specify) '.$data->othersLabel);
        }

        $put('C35', $data->start);
        $put('J35', $data->performerName);
        $put('J38', $data->performerDesignation);

        return $cells;
    }

    private function load(string $xml): DOMDocument
    {
        $doc = new DOMDocument;
        $doc->preserveWhiteSpace = true;

        if (! $doc->loadXML($xml)) {
            throw new RuntimeException('The job order template contains invalid XML.');
        }

        return $doc;
    }
}
