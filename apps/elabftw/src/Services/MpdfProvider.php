<?php

/**
 * @author Nicolas CARPi <nico-git@deltablot.email>
 * @copyright 2012 Nicolas CARPi
 * @see https://www.elabftw.net Official website
 * @license AGPL-3.0
 * @package elabftw
 */

declare(strict_types=1);

namespace Elabftw\Services;

use Elabftw\Elabftw\FsTools;
use Elabftw\Interfaces\MpdfProviderInterface;
use Mpdf\Config\ConfigVariables;
use Mpdf\Config\FontVariables;
use Mpdf\Mpdf;
use Override;

use function dirname;
use function is_dir;
use function is_file;
use function is_writable;
use function mkdir;
use function sys_get_temp_dir;

/**
 * Get an instance of mpdf
 */
final class MpdfProvider implements MpdfProviderInterface
{
    public function __construct(private string $author, private string $format = 'A4', private bool $pdfa = false) {}

    #[Override]
    public function isPdfa(): bool
    {
        return $this->pdfa;
    }

    #[Override]
    public function getInstance(): Mpdf
    {
        $defaultConfig = (new ConfigVariables())->getDefaults();
        $defaultFontConfig = (new FontVariables())->getDefaults();
        $fontDirs = $defaultConfig['fontDir'];
        $fontData = $defaultFontConfig['fontdata'];
        $extraFontDirs = array(dirname(__DIR__, 2) . '/web/assets/fonts');
        $droidCjkDir = dirname(__DIR__, 2) . '/share/fonts';
        if (is_dir($droidCjkDir) && is_file($droidCjkDir . '/DroidSansFallback.ttf')) {
            $extraFontDirs[] = $droidCjkDir;
            $fontData['smartlabcjk'] = array(
                'R' => 'DroidSansFallback.ttf',
            );
            if (is_file($droidCjkDir . '/NotoSansSC-wght.ttf')) {
                $fontData['smartlabnotocjk'] = array(
                    'R' => 'NotoSansSC-wght.ttf',
                    'B' => 'NotoSansSC-wght.ttf',
                    'I' => 'NotoSansSC-wght.ttf',
                    'BI' => 'NotoSansSC-wght.ttf',
                    'useOTL' => 0xFF,
                );
            }
            if (is_file($droidCjkDir . '/NotoSansSymbols-Regular.ttf')) {
                $fontData['smartlabsymbols'] = array(
                    'R' => 'NotoSansSymbols-Regular.ttf',
                    'B' => 'NotoSansSymbols-Bold.ttf',
                );
            }
            if (is_file($droidCjkDir . '/NotoSansSymbols2-Regular.ttf')) {
                $fontData['smartlabsymbols2'] = array(
                    'R' => 'NotoSansSymbols2-Regular.ttf',
                );
            }
        }

        $defaultFont = isset($fontData['smartlabnotocjk']) ? 'smartlabnotocjk' : 'dejavusans';

        // create the pdf
        $mpdf = new Mpdf(array(
            'format' => $this->format,
            'tempDir' => $this->getWritableTempDir(),
            'mode' => 'utf-8',
            'fontDir' => array_merge($fontDirs, $extraFontDirs),
            'fontdata' => $fontData,
            'default_font' => $defaultFont,
            'useSubstitutions' => true,
            'backupSubsFont' => array('smartlabnotocjk', 'smartlabcjk', 'smartlabsymbols2', 'smartlabsymbols', 'dejavusanscondensed', 'sun-exta', 'freesans'),
            // disallow getting external things
            'whitelistStreamWrappers' => array(''),
        ));

        // make sure we can read the pdf in a long time
        // will embed the font and make the pdf bigger
        $mpdf->PDFA = $this->pdfa;
        // force pdfa compliance (things like removing alpha channel of png images)
        if ($this->pdfa) {
            $mpdf->PDFAauto = true;
        }

        // make sure header and footer are not overlapping the body text
        $mpdf->setAutoTopMargin = 'stretch';
        $mpdf->setAutoBottomMargin = 'stretch';

        // set metadata
        $mpdf->SetAuthor($this->author);
        $mpdf->SetTitle('eLabFTW pdf');
        $mpdf->SetSubject('eLabFTW pdf');
        $mpdf->SetCreator('www.elabftw.net');

        return $mpdf;
    }

    private function getWritableTempDir(): string
    {
        $cacheDir = FsTools::getCacheFolder('mpdf');
        $mpdfSubDir = $cacheDir . '/mpdf';
        if (is_writable($cacheDir) && (!is_dir($mpdfSubDir) || is_writable($mpdfSubDir))) {
            return $cacheDir;
        }

        $fallbackDir = sys_get_temp_dir() . '/smartlab-mpdf';
        if (!is_dir($fallbackDir)) {
            mkdir($fallbackDir, 0770, true);
        }
        return $fallbackDir;
    }
}
