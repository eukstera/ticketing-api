@php
    /*
     * Replica of the ICTS Job Request and Customer Satisfaction forms. Every
     * element is placed with coordinates measured on a 1240 px wide render of
     * the Excel form (one A4 page = 1240 px), converted to points here.
     */
    $k = 595.3 / 1240;
    $pt = fn (float $px): string => round($px * $k, 2).'pt';
    $esc = fn (?string $text): string => e((string) $text);

    // Text whose vertical centre is $y. $align: l = starts at $x, c = centred on $x.
    $text = function (float $x, float $y, string $html, float $size = 7.6, string $style = '', string $align = 'l', float $width = 900) use ($pt, $k) {
        $left = match ($align) {
            'c' => $x - $width / 2,
            'r' => $x - $width,
            default => $x,
        };
        $top = $y * $k - $size * 0.6;

        return '<div class="a" style="left:'.$pt($left).';top:'.round($top, 2).'pt;width:'.$pt($width).';font-size:'.$size.'pt;text-align:'.match ($align) { 'c' => 'center', 'r' => 'right', default => 'left' }.';'.$style.'">'.$html.'</div>';
    };
    $label = fn (float $x, float $y, string $s, float $size = 7.4, string $align = 'l', float $width = 900) => $text($x, $y, e($s), $size, 'font-weight:bold;', $align, $width);
    $line = fn (float $x1, float $x2, float $y, float $weight = 0.7) => '<div class="a" style="left:'.$pt($x1).';top:'.$pt($y).';width:'.$pt($x2 - $x1).';height:0;border-top:'.$weight.'pt solid #000;"></div>';
    $vline = fn (float $x, float $y1, float $y2, float $weight = 0.7) => '<div class="a" style="left:'.$pt($x).';top:'.$pt($y1).';width:0;height:'.$pt($y2 - $y1).';border-left:'.$weight.'pt solid #000;"></div>';
    $rect = fn (float $x1, float $y1, float $x2, float $y2, float $weight = 0.7) => '<div class="a" style="left:'.$pt($x1).';top:'.$pt($y1).';width:'.round(($x2 - $x1) * $k - 2 * $weight, 2).'pt;height:'.round(($y2 - $y1) * $k - 2 * $weight, 2).'pt;border:'.$weight.'pt solid #000;"></div>';
    $mark = fn (float $x1, float $y1, float $x2, float $y2, bool $ticked, float $weight = 0.7) => $rect($x1, $y1, $x2, $y2, $weight).($ticked ? $text(($x1 + $x2) / 2, ($y1 + $y2) / 2, 'X', 8.6, 'font-weight:bold;', 'c', $x2 - $x1) : '');
    $image = fn (string $src, float $x, float $y, float $w, ?float $h = null) => '<img class="a" src="'.$src.'" style="left:'.$pt($x).';top:'.$pt($y).';width:'.$pt($w).($h === null ? '' : ';height:'.$pt($h)).';">';
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>JO {{ $d->number }}</title>
<style>
    @page { size: A4 portrait; margin: 0; }
    html, body { margin: 0; padding: 0; }
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 7.6pt; color: #000; }
    .page { position: relative; width: 595.3pt; height: 835pt; overflow: hidden; }
    .break { page-break-after: always; }
    .a { position: absolute; margin: 0; padding: 0; white-space: nowrap; line-height: 1.2; }
</style>
</head>
<body>

{{-- ============================ PAGE 1: IT JOB REQUEST FORM ============================ --}}
<div class="page break">
    {!! $image($img['header_job_order'], 72, 37, 1092) !!}

    {!! $text(620, 222, 'IT JOB REQUEST FORM', 11.5, 'font-weight:bold;', 'c') !!}
    {!! $text(616, 259, 'Kindly fill out this form completely. For Long Term tasks or  will require more than 7 days of', 9.8, 'font-style:italic;', 'c', 1100) !!}
    {!! $text(616, 288, 'completion, create and <u>retain a duplicate copy</u> of this form.', 9.8, 'font-style:italic;', 'c', 1100) !!}

    {!! $label(85, 323, 'IT JOB ORDER NO. :') !!}
    {!! $text(418, 323, $esc($d->number), 7.6, 'font-weight:bold;', 'c', 160) !!}
    {!! $line(370, 463, 336) !!}

    {!! $label(85, 352, 'DIVISION / SECTION :') !!}
    {!! $label(85, 382, 'TYPE OF EQUIPMENT:') !!}
    {!! $label(85, 412, 'MAKER & MODEL NUMBER:') !!}
    {!! $label(85, 493, 'SERIAL NUMBER:') !!}
    {!! $text(492, 352, $esc($d->division), 7.6, 'font-weight:bold;', 'c', 300) !!}
    {!! $text(492, 382, $esc($d->equipment), 7.6, '', 'c', 300) !!}
    @foreach ([367, 396, 426, 452, 479, 508] as $y)
        {!! $line(340, 643, $y) !!}
    @endforeach

    {!! $label(878, 323, 'PRIORITY') !!}
    {!! $mark(843, 337, 873, 367, $d->priority === 'ihomis') !!}
    {!! $mark(843, 367, 873, 397, $d->priority === 'urgent') !!}
    {!! $mark(843, 397, 873, 427, $d->priority === 'important') !!}
    {!! $mark(843, 480, 873, 509, $d->priority === 'long_term') !!}
    {!! $text(878, 351, '1) URGENT &amp; IHOMIS RELATED', 7.3) !!}
    {!! $text(878, 381, '2) URGENT', 7.3) !!}
    {!! $text(878, 411, '3) IMPORTANT BUT NOT URGENT', 7.3) !!}
    {!! $text(878, 439, 'Expected Date to Accomplish Task:', 5.9) !!}
    {!! $text(878, 493, '4) LONG TERM', 7.3) !!}
    {!! $text(878, 523, 'Expected Date to Accomplish Task:', 5.9) !!}
    {!! $text(878, 461, $d->priority !== null && $d->priority !== 'long_term' ? $esc($d->expectedDate) : '', 6.6, 'text-decoration:underline;') !!}
    @unless ($d->expectedDate !== null && $d->priority !== null && $d->priority !== 'long_term')
        {!! $line(878, 1117, 475) !!}
    @endunless
    {!! $text(878, 545, $d->priority === 'long_term' ? $esc($d->expectedDate) : '', 6.6, 'text-decoration:underline;') !!}
    @unless ($d->expectedDate !== null && $d->priority === 'long_term')
        {!! $line(878, 1117, 558) !!}
    @endunless

    {!! $label(85, 576, 'DETAIL OF') !!}
    {!! $label(85, 599, 'WORKS') !!}
    {!! $label(85, 623, 'REQUESTED:') !!}
    @foreach ([600, 632, 662] as $i => $y)
        {!! $line(195, 1157, $y) !!}
        {!! $text(198, $y - 17, $esc($d->detailLines[$i] ?? ''), 7.2) !!}
    @endforeach

    {!! $label(85, 695, 'REQUESTED BY:') !!}
    {!! $label(664, 695, 'RECOMMENDING APPROVAL:') !!}
    {!! $text(212, 741, $esc($d->requesterName), 7.4, 'text-decoration:underline;', 'c', 260) !!}
    @if ($d->requesterName === null)
        {!! $line(85, 340, 751) !!}
    @endif
    {!! $line(663, 912, 751) !!}
    {!! $label(210, 774, 'SIGNATURE OVER PRINTED NAME', 5.4, 'c', 400) !!}
    {!! $label(814, 774, 'SIGNATURE OVER PRINTED NAME', 5.4, 'c', 400) !!}
    {!! $label(85, 806, 'Designation:') !!}
    {!! $line(195, 310, 818) !!}
    {!! $text(198, 806, $esc($d->requesterDesignation), 7.2) !!}
    {!! $label(85, 832, 'Date:') !!}
    {!! $line(157, 310, 845) !!}
    {!! $text(233, 832, $esc($d->requestedDate), 7.4, '', 'c', 150) !!}
    {!! $label(664, 806, 'Division/Section Head') !!}
    {!! $label(664, 832, 'Date :________________') !!}

    {!! $label(620, 877, '------------------------PLS. DO NOT  FILL OUT BELOW THIS LINE ------------------------', 7.4, 'c', 1100) !!}

    {{-- outer box and its dividers --}}
    {!! $rect(81, 889, 1157, 1700) !!}
    {!! $vline(644, 889, 1135) !!}
    {!! $line(81, 1157, 1135) !!}
    {!! $line(81, 1157, 1345) !!}
    {!! $line(81, 1157, 1502) !!}

    {!! $text(85, 903, 'NATURE OF TASK', 7.4, 'font-weight:bold;text-decoration:underline;') !!}
    {!! $mark(157, 942, 195, 972, false, 1.6) !!}
    {!! $mark(157, 997, 195, 1027, false, 1.6) !!}
    {!! $mark(157, 1052, 195, 1082, false, 1.6) !!}
    {!! $label(198, 956, 'SIMPLE') !!}
    {!! $label(198, 1011, 'MODERATE') !!}
    {!! $label(198, 1066, 'COMPLEX') !!}
    {!! $text(345, 956, '( 1 to 7 Hours)', 7.2) !!}
    {!! $text(345, 1011, '( 8 Hours to 7 Working Days)', 7.2) !!}
    {!! $text(345, 1066, '( 8 Working Days to 30 Working Days)', 6.2) !!}

    {!! $text(663, 903, 'TASK CATEGORY', 7.4, 'font-weight:bold;text-decoration:underline;') !!}
    @foreach ([
        ['hardware', 'Hardware', 916, 942],
        ['software', 'Software', 943, 969],
        ['network', 'Network Connection', 970, 997],
        ['photoshop', 'Photoshop Layout', 998, 1025],
        ['audio', 'Audio/Video', 1026, 1052],
        ['tutorial', 'Tutorial / Training', 1053, 1081],
        ['others', 'Others (Pls. Specify)', 1082, 1108],
    ] as [$key, $caption, $top, $bottom])
        {!! $mark(752, $top, 807, $bottom, $d->category === $key) !!}
        {!! $text(811, ($top + $bottom) / 2, $esc($caption), 7.3) !!}
    @endforeach
    {!! $line(987, 1115, 1103) !!}
    {!! $text(990, 1093, $esc($d->othersLabel), 6.8) !!}

    {!! $text(620, 1147, 'PERFORMANCE', 7.4, 'font-weight:bold;text-decoration:underline;', 'c', 400) !!}
    {!! $label(198, 1175, 'START (Date & Time:)') !!}
    {!! $text(198, 1219, $esc($d->start), 7.2) !!}
    {!! $line(194, 494, 1231) !!}
    {!! $label(756, 1175, 'PERFORMED BY:') !!}
    {!! $text(663, 1219, $esc($d->performerName), 7.2) !!}
    {!! $line(660, 968, 1232) !!}
    {!! $label(814, 1245, 'SIGNATURE OVER PRINTED NAME', 5.4, 'c', 400) !!}
    {!! $label(198, 1271, 'FINISHED  (Date & Time:)') !!}
    {!! $line(194, 494, 1309) !!}
    {!! $text(663, 1296, $esc($d->performerDesignation), 7.2) !!}
    {!! $line(660, 968, 1310) !!}
    {!! $label(814, 1323, 'DESIGNATION', 5.4, 'c', 400) !!}

    {!! $text(85, 1358, 'TECHNICAL REMARKS / NOTES / RECOMMENDATIONS :', 7.4, 'font-weight:bold;text-decoration:underline;') !!}
    @foreach ([1404, 1436, 1468, 1501] as $y)
        {!! $line(82, 548, $y) !!}
        {!! $line(644, 1157, $y) !!}
    @endforeach

    {!! $text(85, 1529, 'MATERIALS / PARTS NEEDED FOR REPAIR / REPLACEMENT:', 7.4, 'font-weight:bold;text-decoration:underline;') !!}
    {!! $label(120, 1555, 'QTY', 7.4, 'c', 120) !!}
    {!! $label(253, 1555, 'UNIT', 7.4, 'c', 120) !!}
    {!! $label(344, 1555, 'ITEM DESCRIPTION') !!}
    {!! $label(811, 1555, 'ESTIMATED COST') !!}
    @foreach ([1594, 1621, 1648, 1674] as $y)
        {!! $line(82, 158, $y) !!}
        {!! $line(194, 310, $y) !!}
        {!! $line(340, 751, $y) !!}
        {!! $line(807, 968, $y) !!}
    @endforeach
</div>

{{-- ============================ PAGE 2: CUSTOMER SATISFACTION SURVEY ============================ --}}
<div class="page">
    {!! $image($img['header_survey'], 140, 48, 940, 103) !!}
    {!! $image($img['form_code_survey'], 1040, 47, 125) !!}

    {!! $label(155, 190, 'IT JOB ORDER NO. :', 7.6) !!}
    {!! $text(549, 190, $esc($d->number), 7.6, 'font-weight:bold;', 'c', 300) !!}
    {!! $line(383, 715, 202) !!}
    {!! $label(607, 225, 'CUSTOMER SATISFACTION SURVEY', 10.1, 'c', 900) !!}
    {!! $label(650, 265, 'Please let us know how well we have served you and how we can further improve our service by completing this', 6.9, 'c', 1100) !!}
    {!! $label(650, 288, 'feedback form. Please mark a CHECK over the SMILEY FACE that best represents your response. Thank you.', 6.9, 'c', 1100) !!}

    {!! $label(155, 337, 'SAMPLE:', 7.4) !!}
    {!! $text(291, 337, 'Strongly Agree', 7.4) !!}
    {!! $image($img['smiley'][0], 452, 319, 28) !!}
    {!! $text(462, 333, '&#10004;', 17, '', 'c', 60) !!}

    @php
        $columns = [757, 842, 928, 1016, 1105];
        $smileys = [759, 840, 923, 1012, 1099];
        $sizes = [29, 30, 30, 31, 34];
        $headers = [['Strongly', 'Agree'], ['Agree'], ['Neither', 'Agree nor', 'Disagree'], ['Disagree'], ['Strongly', 'Disagree']];
        $headerRow = function (float $top) use ($columns, $headers, $label) {
            $out = '';
            foreach ($columns as $i => $cx) {
                $count = count($headers[$i]);
                $first = $count === 3 ? 10 : ($count === 2 ? 19 : 27);
                foreach ($headers[$i] as $n => $word) {
                    $out .= $label($cx, $top + $first + $n * 17, $word, 5.3, 'c', 130);
                }
            }

            return $out;
        };
        $smileyRow = function (float $y) use ($smileys, $sizes, $img, $image) {
            $out = '';
            foreach ($smileys as $i => $cx) {
                $out .= $image($img['smiley'][$i], $cx - $sizes[$i] / 2, $y - $sizes[$i] / 2, $sizes[$i]);
            }

            return $out;
        };
    @endphp

    {{-- first block has no category title --}}
    {!! $line(715, 1148, 368, 1.6) !!}
    {!! $vline(1147, 368, 463, 1.6) !!}
    {!! $vline(152, 420, 463, 1.2) !!}
    {!! $headerRow(368) !!}
    {!! $text(155, 442, 'Received the appropriate service(s) needed.', 7.2, 'font-style:italic;') !!}
    {!! $smileyRow(443) !!}

    @foreach ([
        ['ASSURANCE', 470, 631, 496, ['Timely response was given to acknowledge and act on the request.', 'Assurance as well as the committed Time to finish was given, if service', 'request is tagged as "Complex" requiring more than 7 days of', 'completion.'], 545, 567],
        ['RELIABILITY', 638, 763, 664, ['The staff was knowledgeable, trouble or errors encountered were', 'eliminated and reported problem was addressed and resolved.'], 715, 721],
        ['COMMUNICATION', 768, 893, 794, ['Methods or actions done to address my request was explained clearly', 'in a courteous, approachable and professional manner.'], 845, 855],
        ['ACCESS &amp; FACILITIES', 901, 1002, 927, ['I was comfortable doing my transactions at the ICTS office.'], 977, 977],
        ['INTEGRITY', 1013, 1113, 1038, ['The service(s) rendered by the ICTS staff were just, honest and fair.'], 1088, 1089],
        ['OUTCOME', 1125, 1231, 1151, ['Overall, I was happy and satisfied with the service(s) I received.'], 1203, 1208],
    ] as [$title, $top, $bottom, $titleY, $statement, $statementY, $smileyY])
        {!! $rect(152, $top, 1148, $bottom, 1.6) !!}
        {!! $text(434, $titleY, $title, 7.4, 'font-weight:bold;', 'c', 500) !!}
        {!! $headerRow($top) !!}
        @foreach ($statement as $n => $sentence)
            {!! $text(155, $statementY + $n * 21, $esc($sentence), 7.2, 'font-style:italic;') !!}
        @endforeach
        {!! $smileyRow($smileyY) !!}
    @endforeach

    {!! $label(155, 1254, 'OTHER COMMENTS AND RECOMMENDATIONS :', 7.4) !!}
    {!! $line(152, 1147, 1293) !!}
    {!! $line(152, 1147, 1318) !!}

    {!! $label(650, 1361, 'ACKNOWLEDGEMENT OF SERVICE(S) RENDERED', 9.0, 'c', 900) !!}
    {!! $rect(152, 1378, 1147, 1448) !!}
    {!! $label(381, 1411, 'ACCEPTED BY:', 6.9, 'r', 300) !!}
    {!! $label(387, 1388, 'NAME :', 7.0) !!}
    {!! $label(387, 1411, 'DESIGNATION:', 7.0) !!}
    {!! $label(387, 1434, 'DATE:', 7.0) !!}
    {!! $line(714, 970, 1401) !!}
    {!! $line(714, 970, 1424) !!}
</div>

</body>
</html>
