<?php

/**
 * @author Nicolas CARPi <nico-git@deltablot.email>
 * @copyright 2012 Nicolas CARPi
 * @see https://www.elabftw.net Official website
 * @license AGPL-3.0
 * @package elabftw
 */

declare(strict_types=1);

namespace Elabftw\Make;

use DateTimeImmutable;
use Elabftw\Elabftw\Env;
use Elabftw\Elabftw\FsTools;
use Elabftw\Elabftw\Tools;
use Elabftw\Enums\BodyContentType;
use Elabftw\Enums\Classification;
use Elabftw\Enums\EntityType;
use Elabftw\Enums\Storage;
use Elabftw\Interfaces\MpdfProviderInterface;
use Elabftw\Models\AbstractEntity;
use Elabftw\Models\Changelog;
use Elabftw\Models\Notifications\MathjaxFailed;
use Elabftw\Models\Notifications\PdfAppendmentFailed;
use Elabftw\Models\Notifications\PdfGenericError;
use Elabftw\Models\SmartlabExperiments;
use Elabftw\Models\Users\Users;
use Elabftw\Services\Filter;
use Elabftw\Services\Tex2Svg;
use Elabftw\Traits\TwigTrait;
use League\Flysystem\Filesystem;
use Psr\Log\LoggerInterface;
use setasign\Fpdi\FpdiException;
use Override;

use function date;
use function implode;
use function json_encode;
use function preg_match;
use function str_replace;
use function strlen;
use function strtolower;

/**
 * Create a pdf from an Entity
 */
class MakePdf extends AbstractMakePdf
{
    use TwigTrait;

    private const string GHS_FOLDER = '/elabftw/web/assets/images/ghs';

    public array $failedAppendPdfs = array();

    // collect paths of files to delete
    public array $trash = array();

    protected bool $includeAttachments = false;

    protected AbstractEntity $Entity;

    private FileSystem $cacheFs;

    private bool $pdfa;

    public function __construct(
        private LoggerInterface $log,
        MpdfProviderInterface $mpdfProvider,
        protected Users $requester,
        protected array $entityArr,
        bool $includeChangelog = false,
        Classification $classification = Classification::None,
    ) {
        parent::__construct(
            mpdfProvider: $mpdfProvider,
            includeChangelog: $includeChangelog,
            classification: $classification,
        );

        $this->pdfa = $mpdfProvider->isPdfa();
        $this->mpdf->SetTitle($this->getTitle());
        $this->mpdf->SetKeywords($this->getKeywords());

        // suppress the "A non-numeric value encountered" error from mpdf
        // see https://github.com/baselbers/mpdf/commit
        // 5cbaff4303604247f698afc6b13a51987a58f5bc#commitcomment-23217652
        error_reporting(E_ERROR);

        $this->cacheFs = Storage::CACHE->getStorage()->getFs();
        if ($this->pdfa || $this->requester->userData['inc_files_pdf']) {
            $this->includeAttachments = true;
        }
    }

    public function __destruct()
    {
        // delete the temporary files once we're done with it
        foreach ($this->trash as $filename) {
            $this->cacheFs->delete($filename);
        }
    }

    /**
     * Generate pdf and return it as string
     */
    #[Override]
    public function getFileContent(): string
    {
        $this->loopOverEntries();
        $output = $this->mpdf->OutputBinaryData();
        // use strlen for binary data, not mb_strlen
        $this->contentSize = strlen($output);
        if ($this->errors && $this->notifications) {
            $Notifications = new PdfGenericError($this->requester);
            $Notifications->create();
        }
        return $output;
    }

    /**
     * Replace weird characters by underscores
     */
    #[Override]
    public function getFileName(): string
    {
        $now = (new DateTimeImmutable())->format('Y-m-d');
        $date = $this->Entity->entityData['date'] ?? $now;

        return sprintf('%s-%s.pdf', $date, Filter::forFilesystem($this->getTitle()));
    }

    protected function getTitle(): string
    {
        return $this->Entity->entityData['title'] ?? 'eLabFTW PDF';
    }

    /** @psalm-suppress InvalidReturnType */
    protected function getKeywords(): string
    {
        /** @psalm-suppress InvalidReturnStatement */
        return str_replace('|', ' ', $this->Entity->entityData['tags'] ?? '');
    }

    /**
     * Loop over entries, change the entity id and add its content to the pdf
     */
    private function loopOverEntries(): void
    {
        $entriesCount = count($this->entityArr);
        foreach ($this->entityArr as $key => $entity) {
            $this->Entity = $entity;
            $this->addEntry();

            if ($key !== $entriesCount - 1) {
                $this->mpdf->AddPageByArray(array(
                    'sheet-size' => $this->requester->userData['pdf_format'],
                ));
            }
        }
    }

    /**
     * Add an entry to mpdf
     */
    private function addEntry(): void
    {
        // write content
        $this->mpdf->WriteHTML($this->getContent());

        if ($this->requester->userData['append_pdfs']) {
            $this->appendPdfs($this->getAttachedPdfs());
            if ($this->failedAppendPdfs) {
                /** @psalm-suppress PossiblyNullArgument */
                $this->errors[] = new PdfAppendmentFailed(
                    $this->requester,
                    $this->Entity->id,
                    $this->Entity->entityType->toPage(),
                    implode(', ', $this->failedAppendPdfs),
                );
            }
        }
    }

    /**
     * Get the final html content with tex expressions converted in svg by tex2svg
     */
    private function getContent(): string
    {
        $Tex2Svg = new Tex2Svg($this->log, $this->mpdf, $this->getHtml());
        $content = $Tex2Svg->getContent();

        // Inform user that there was a problem with Tex rendering
        if ($Tex2Svg->mathJaxFailed) {
            /** @psalm-suppress PossiblyNullArgument */
            $this->errors[] = new MathjaxFailed($this->requester, $this->Entity->id, $this->Entity->entityType->toPage());
        }
        return $this->sanitizePdfGlyphFallbacks($content);
    }

    private function sanitizePdfGlyphFallbacks(string $content): string
    {
        $content = strtr($content, array(
            '“' => '"',
            '”' => '"',
            '„' => '"',
            '‟' => '"',
            '‘' => "'",
            '’' => "'",
            '‚' => "'",
            '‛' => "'",
            '（' => '(',
            '）' => ')',
            '【' => '[',
            '】' => ']',
            '〔' => '[',
            '〕' => ']',
            '「' => '"',
            '」' => '"',
            '『' => '"',
            '』' => '"',
        ));

        // mPDF does not handle color emoji or icon-font private-use glyphs reliably.
        $sanitized = preg_replace('/[\x{E000}-\x{F8FF}\x{FE0E}\x{FE0F}\x{1F000}-\x{1FAFF}]/u', '', $content);
        return $sanitized ?? $content;
    }

    /**
     * Build HTML content that will be fed to mpdf->WriteHTML()
     */
    private function getHtml(): string
    {
        $date = new DateTimeImmutable($this->Entity->entityData['date'] ?? date('Ymd'));

        $locked = $this->Entity->entityData['locked'];
        $lockerName = $this->Entity->getLockerFullname();
        $lockDate = Filter::separateDateAndTime($this->Entity->entityData['locked_at'] ?? '');

        $timestamped = $this->Entity->entityData['timestamped'];
        $timestamperName = $this->Entity->getTimestamperFullname();
        $timestampedAt = Filter::separateDateAndTime($this->Entity->entityData['timestamped_at'] ?? '');

        // Format date for pdf title
        if ($this->Entity->entityData['timestamped'] === 1) {
            $localDate = Filter::formatLocalDate(new DateTimeImmutable($this->Entity->entityData['timestamped_at']));
        }

        // read the content of the thumbnail here to feed the template
        foreach ($this->Entity->entityData['uploads'] as $key => $upload) {
            $storageEnum = Storage::tryFrom($upload['storage']) ?? Storage::LOCAL;
            $storageFs = $storageEnum->getStorage()->getFs();
            $thumbnail = $upload['long_name'] . '_th.jpg';
            // no need to filter on extension, just insert the thumbnail if it exists
            if ($storageFs->fileExists($thumbnail)) {
                $this->Entity->entityData['uploads'][$key]['base64_thumbnail'] = base64_encode($storageFs->read($thumbnail));
            }
        }

        $Changelog = new Changelog($this->Entity);

        $baseUrls = array();
        $siteUrl = Env::asUrl('SITE_URL');
        foreach (array(EntityType::Items, EntityType::Experiments) as $entityType) {
            $baseUrls[$entityType->value] = sprintf('%s/%s', $siteUrl, $entityType->toPage());
        }

        $body = $this->getBody();
        $smartlabAssayPack = $this->readSmartlabAssayPackForPdf();
        $entityText = json_encode($this->Entity->entityData, JSON_UNESCAPED_UNICODE);
        $useCjk = (int) $this->requester->userData['cjk_fonts'] === 1
            || $smartlabAssayPack !== array()
            || $this->containsCjk($body)
            || ($entityText !== false && $this->containsCjk($entityText));

        $renderArr = array(
            'body' => $body,
            'changes' => $Changelog->readAllWithAbsoluteUrls(),
            'classification' => $this->classification->toHuman(),
            'css' => $this->getCss(),
            'date' => $date->format('Y-m-d'),
            'entityData' => $this->Entity->entityData,
            'includeChangelog' => $this->includeChangelog,
            'ghsImagesPath' => self::GHS_FOLDER,
            'includeFiles' => $this->includeAttachments,
            'locked' => $locked,
            'lockDate' => $lockDate['date'],
            'lockTime' => $lockDate['time'],
            'lockerName' => $lockerName,
            'timestamped' => $timestamped,
            'timestampDate' => $timestampedAt['date'],
            'timestampTime' => $timestampedAt['time'],
            'timestamperName' => $timestamperName,
            'localDate' => $localDate ?? '',
            'pdfSig' => $this->requester->userData['pdf_sig'],
            'smartlabAssayPack' => $smartlabAssayPack,
            // TODO fix for templates
            'linkBaseUrl' => $baseUrls,
            'url' => sprintf('%s/%s?mode=view&id=%d', $siteUrl, $this->Entity->entityType->toPage(), $this->Entity->id ?? 0),
            'useCjk' => $useCjk,
        );

        return $this->getTwig(Env::asBool('DEV_MODE'))->render('pdf.html', $renderArr);
    }

    private function containsCjk(string $content): bool
    {
        return preg_match('/[\x{2E80}-\x{9FFF}\x{F900}-\x{FAFF}\x{3040}-\x{30FF}\x{AC00}-\x{D7AF}]/u', $content) === 1;
    }

    private function readSmartlabAssayPackForPdf(): array
    {
        if ($this->Entity->entityType !== EntityType::Experiments || (int) ($this->Entity->id ?? 0) < 1) {
            return array();
        }

        $assayPack = (new SmartlabExperiments($this->requester))->readAssayPack((int) $this->Entity->id);
        if (($assayPack['pack_key'] ?? '') === '') {
            return array();
        }

        return array(
            'label' => $this->getAssayPackLabel((string) $assayPack['pack_key']),
            'mode' => (($assayPack['pack_mode'] ?? 'quick') === 'standard') ? '标准记录' : '快速记录',
            'qc_status' => $this->formatAssayValue($assayPack['qc_status'] ?? ''),
            'auto_summary' => (string) ($assayPack['auto_summary'] ?? ''),
            'sections' => $this->buildAssayPackSections($assayPack['payload'] ?? array()),
            'qc_feedback' => $assayPack['qc_feedback'] ?? array(),
            'analysis_cards' => $assayPack['analysis_cards'] ?? array(),
            'analysis_rows' => $assayPack['analysis_rows'] ?? array(),
            'analysis_group_rows' => $assayPack['analysis_group_rows'] ?? array(),
            'results' => $this->buildAssayPackResultRows($assayPack),
        );
    }

    private function getAssayPackLabel(string $packKey): string
    {
        return match ($packKey) {
            'western_blot' => 'Western Blot 结构化记录',
            'if' => '免疫荧光 IF 结构化记录',
            'ihc' => '免疫组化 IHC 结构化记录',
            'elisa' => 'ELISA 结构化记录',
            default => 'qPCR 结构化记录',
        };
    }

    private function buildAssayPackSections(array $payload): array
    {
        $sections = array();
        $sectionLabels = array(
            'cover' => '基本信息',
            'sample' => '样本信息',
            'primer' => '引物信息',
            'setup' => '反应体系/仪器',
            'run' => '实验运行条件',
            'reagent' => '试剂/抗体',
            'staining' => '染色条件',
            'imaging' => '成像条件',
            'analysis' => '结果分析',
            'image' => '图像/附件说明',
            'qc' => '质控信息',
        );

        foreach ($payload as $sectionKey => $sectionValue) {
            if ($sectionKey === 'results' || !is_array($sectionValue)) {
                continue;
            }
            $fields = array();
            foreach ($sectionValue as $fieldKey => $fieldValue) {
                $value = $this->formatAssayValue($fieldValue);
                if ($value === '') {
                    continue;
                }
                $fields[] = array(
                    'label' => $this->humanizeAssayKey((string) $fieldKey),
                    'value' => $value,
                );
            }
            if ($fields !== array()) {
                $sections[] = array(
                    'label' => $sectionLabels[(string) $sectionKey] ?? $this->humanizeAssayKey((string) $sectionKey),
                    'fields' => $fields,
                );
            }
        }

        return $sections;
    }

    private function buildAssayPackResultRows(array $assayPack): array
    {
        $rows = array();
        $sourceRows = ($assayPack['pack_key'] ?? '') === 'qpcr'
            ? ($assayPack['results'] ?? array())
            : ($assayPack['payload']['results'] ?? array());

        foreach ($sourceRows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $formattedRow = array();
            foreach ($row as $key => $value) {
                $formattedValue = $this->formatAssayValue($value);
                if ($formattedValue === '' || (string) $key === 'is_outlier' && $formattedValue === '0') {
                    continue;
                }
                $formattedRow[] = array(
                    'label' => $this->humanizeAssayKey((string) $key),
                    'value' => $formattedValue,
                );
            }
            if ($formattedRow !== array()) {
                $rows[] = $formattedRow;
            }
        }

        return $rows;
    }

    private function formatAssayValue(mixed $value): string
    {
        if ($value === null || $value === false) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? '是' : '否';
        }
        if (is_scalar($value)) {
            $stringValue = trim((string) $value);
            return match ($stringValue) {
                '', '0' => $stringValue,
                'yes' => '是',
                'no' => '否',
                'up' => '上调',
                'down' => '下调',
                'stable' => '稳定',
                default => $stringValue,
            };
        }
        if (!is_array($value)) {
            return '';
        }

        $parts = array();
        foreach ($value as $nestedKey => $nestedValue) {
            $nestedFormattedValue = $this->formatAssayValue($nestedValue);
            if ($nestedFormattedValue === '') {
                continue;
            }
            $parts[] = sprintf('%s: %s', $this->humanizeAssayKey((string) $nestedKey), $nestedFormattedValue);
        }
        return implode('；', $parts);
    }

    private function humanizeAssayKey(string $key): string
    {
        $labels = array(
            'experiment_type' => '实验类型',
            'objective' => '实验目的',
            'project_code' => '课题编号',
            'operator_name' => '操作者',
            'sample_source' => '样本来源',
            'run_date' => '实验日期',
            'sample_origin' => '样本编号/来源',
            'treatment_group' => '处理组',
            'rna_concentration' => 'RNA 浓度',
            'a260_a280' => 'A260/A280',
            'reverse_transcription_batch' => '反转录批次',
            'target_gene' => '目标基因',
            'reference_gene' => '内参基因',
            'primer_code' => '引物编号',
            'amplicon_bp' => '扩增长度',
            'annealing_temperature' => '退火温度',
            'kit_name' => '试剂盒',
            'reaction_volume' => '反应体积',
            'cycler_program' => '扩增程序',
            'instrument_model' => '仪器型号',
            'negative_control_amplified' => '阴性对照扩增',
            'melting_curve_single_peak' => '熔解曲线单峰',
            'notes' => '备注',
            'sample_label' => '样本',
            'group_label' => '分组',
            'ct_value' => 'Ct',
            'reference_ct' => '内参 Ct',
            'delta_ct' => 'ΔCt',
            'delta_delta_ct' => 'ΔΔCt',
            'fold_change' => 'Fold change',
            'replicate_count' => '重复数',
            'is_outlier' => '异常值',
            'label' => '名称',
            'note' => '备注',
            'conclusion' => '结论',
            'primary_antibody' => '一抗',
            'secondary_antibody' => '二抗',
            'primary_dilution' => '一抗稀释',
            'secondary_dilution' => '二抗稀释',
            'detection_reagent' => '显色/检测试剂',
            'lysis_buffer' => '裂解液',
            'protein_concentration' => '蛋白浓度',
            'loading_amount' => '上样量',
            'denaturation_condition' => '变性条件',
            'gel_percentage' => '胶浓度',
            'electrophoresis_condition' => '电泳条件',
            'transfer_condition' => '转膜条件',
            'membrane_type' => '膜类型',
            'target_band_kda' => '目标条带',
            'reference_control' => '内参',
            'normalization_method' => '归一化方法',
            'target_signal' => '目标信号',
            'reference_signal' => '内参信号',
            'normalized_signal' => '归一化信号',
            'trend' => '趋势',
            'positive_ratio' => '阳性比例',
            'mean_intensity' => '平均强度',
            'colocalization' => '共定位',
            'cell_count' => '细胞数',
            'localization' => '定位',
            'staining_intensity' => '染色强度',
            'h_score' => 'H-score',
            'irs_score' => 'IRS',
            'tissue_id' => '组织编号',
            'fixative' => '固定液',
            'embedding_batch' => '包埋批次',
            'section_thickness' => '切片厚度',
            'antigen_retrieval' => '抗原修复',
            'blocking' => '封闭',
            'dab_time' => 'DAB 时间',
            'counterstain' => '复染',
            'sample_type' => '样本类型',
            'dilution_factor' => '稀释倍数',
            'batch_id' => '批次',
            'target_name' => '检测指标',
            'capture_antibody' => '包被抗体',
            'detection_antibody' => '检测抗体',
            'substrate_name' => '底物',
            'reader_model' => '读板仪',
            'wavelength_nm' => '检测波长',
            'plate_layout' => '孔板布局',
            'incubation_condition' => '孵育条件',
            'wash_cycles' => '洗板次数',
            'standard_curve_r2' => '标准曲线 R²',
            'blank_od450' => 'Blank OD450',
            'cv_threshold' => 'CV 阈值',
            'od450' => 'OD450',
            'concentration' => '浓度',
            'cv_percent' => 'CV%',
        );

        return $labels[$key] ?? str_replace('_', ' ', $key);
    }

    /**
     * Get the body text of an entity and prepare linked images for mpdf
     */
    private function getBody(): string
    {
        $body = $this->Entity->entityData['body'] ?? '';
        if ($this->Entity->entityData['content_type'] === BodyContentType::Markdown->value) {
            // md2html can result in invalid html, see https://github.com/elabftw/elabftw/issues/3076
            // the Filter::body (HTMLPurifier) rescues the invalid parts and thus avoids some MathJax errors
            // the consequence is a slightly different layout
            $body = Filter::body(Tools::md2html($body));
        }

        // now this part of the code will look for embedded images in the text and download them from storage and passes them to mpdf via variables.
        // it would have been preferable to avoid such complexity and regexes, but this is the most robust way to get images in there.
        // it works for any storage source
        // mpdf supports jpg, gif, png (+/- transparency), svg, webp, wmf and bmp (not documented but in source).
        // see https://mpdf.github.io/what-else-can-i-do/images.html
        // and https://github.com/mpdf/mpdf/blob/development/src/Image/ImageProcessor.php ImageProcessor::getImage() around line 218
        // and https://github.com/mpdf/mpdf/blob/development/src/Image/ImageTypeGuesser.php
        // the slash (/) in the f parameter might be url encoded (%2F), see https://github.com/elabftw/elabftw/issues/4961
        // a generic regex that asserts that the f parameter is present and well formatted but ignores the order of parameters
        $matches = array();
        preg_match_all(
            '/app\/download\.php\?(?=.*?f=(?:' .
            '[[:alnum:]]{2}(?:\/|%2F)[[:alnum:]]{128}\.(?:jpe?g|gif|png|svg|webp|wmf|bmp)' .
            '|' .
            '[[:alnum:]]{2}(?:\/|%2F)[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\.(?:jpe?g|gif|png|svg|webp|wmf|bmp)' .
          '))[^"]+/i',
            $body,
            $matches
        );
        foreach ($matches[0] as $src) {
            // src will look similar to: app/download.php?f=c2/c2741a{...}016a3.png&amp;storage=1
            // ampersand (&) in html attributes should be encoded (&amp;) so we decode first
            // and parse it to get the file path and storage type
            $query = parse_url(htmlspecialchars_decode($src), PHP_URL_QUERY);
            if (!$query) {
                continue;
            }
            $res = array();
            // parse_str will also do the url decoding (%2F -> /)
            parse_str($query, $res);
            // @phpstan-ignore-next-line (f will be here because of the regex above)
            $longname = (string) $res['f'];
            // there might be no storage value. In this case get it from the uploads table via the long name
            $storage = (int) ($res['storage'] ?? $this->Entity->Uploads->getStorageFromLongname($longname));
            $storageEnum = Storage::tryFrom($storage) ?? Storage::LOCAL;
            $storageFs = $storageEnum->getStorage()->getFs();
            // pass image data to mpdf via variable. See https://mpdf.github.io/what-else-can-i-do/images.html#image-data-as-a-variable
            // avoid using data URLs (data:...) because it adds too many characters to $body, see https://github.com/elabftw/elabftw/issues/3627
            $this->mpdf->imageVars[$longname] = $storageFs->read($longname);
            $body = str_replace($src, "var:$longname", $body);
        }

        /** @psalm-suppress PossiblyInvalidArgument */
        /** @psalm-suppress PossiblyInvalidCast */
        return $this->fixLocalLinks($body);
    }

    /**
     * Look for links to experiments or database made with the # autocompletion and thus relative.
     * We need to make them absolute or they will end up wrong.
     */
    private function fixLocalLinks(string $body): string
    {
        $matches = array();
        preg_match_all('/href="(experiments|database).php/', $body, $matches);
        $i = 0;
        $siteUrl = Env::asUrl('SITE_URL');
        foreach ($matches[0] as $match) {
            $body = str_replace($match, 'href="' . $siteUrl . '/' . $matches[1][$i] . '.php', $body);
            $i += 1;
        }
        return $body;
    }

    /**
     * Get a list of all PDFs that are attached to an entity
     *
     * @return array Empty or array of arrays with information for PDFs array('path/to/file', 'real_name')
     */
    private function getAttachedPdfs(): array
    {
        $uploadsArr = $this->Entity->entityData['uploads'];
        $listOfPdfs = array();

        if (empty($uploadsArr)) {
            return $listOfPdfs;
        }

        foreach ($uploadsArr as $upload) {
            $storageEnum = Storage::tryFrom($upload['storage']) ?? Storage::LOCAL;
            $storageFs = $storageEnum->getStorage()->getFs();
            if ($storageFs->fileExists($upload['long_name']) && strtolower(Tools::getExt($upload['real_name'])) === 'pdf') {
                // the real_name is used in case of error appending it
                // the content is stored in a temporary file so it can be read with appendPdfs()
                $tmpPath = FsTools::getCacheFile();
                $filename = basename($tmpPath);
                $this->cacheFs->writeStream($filename, $storageFs->readStream($upload['long_name']));
                $listOfPdfs[] = array($tmpPath, $upload['real_name']);
                // add the temporary file to the trash
                $this->trash[] = $filename;
            }
        }

        return $listOfPdfs;
    }

    /**
     * Append PDFs attached to an entity
     */
    private function appendPdfs(array $pdfs): void
    {
        foreach ($pdfs as $pdf) {
            // There will be cases where the merging will fail
            // due to incompatibilities of Mpdf (actually fpdi) with the pdfs
            // See https://manuals.setasign.com/fpdi-manual/v2/limitations/
            // These cases will be caught and ignored
            try {
                $numberOfPages = $this->mpdf->setSourceFile($pdf[0]);

                for ($i = 1; $i <= $numberOfPages; $i++) {
                    // Import the ith page of the source PDF file
                    $page = $this->mpdf->importPage($i);

                    // getTemplateSize() is not documented in the MPDF manual
                    // @return array|bool An array with following keys: width, height, 0 (=width), 1 (=height), orientation (L or P)
                    $pageDim = $this->mpdf->getTemplateSize($page);

                    if (is_array($pageDim)) { // satisfy phpstan
                        // add a new (blank) page with the dimensions of the imported page
                        $this->mpdf->AddPageByArray(array(
                            'orientation' => $pageDim['orientation'],
                            'sheet-size' => array($pageDim['width'], $pageDim['height']),
                        ));
                    }

                    // empty the header and footer
                    // cannot be an empty string
                    $this->mpdf->SetHTMLHeader(' ', '', true);
                    $this->mpdf->SetHTMLFooter(' ', '');

                    // add the content of the imported page
                    $this->mpdf->useTemplate($page);
                }
                // not all pdf will be able to be integrated, so for the one that will trigger an exception
                // we simply ignore it and collect information for notification
            } catch (FpdiException) {
                // collect real name of attached pdf
                $this->failedAppendPdfs[] = $pdf[1];
                continue;
            }
        }
    }
}
