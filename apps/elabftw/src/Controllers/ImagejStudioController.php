<?php

declare(strict_types=1);

namespace Elabftw\Controllers;

use Override;

final class ImagejStudioController extends AbstractHtmlController
{
    #[Override]
    protected function getTemplate(): string
    {
        return 'imagej-studio.html';
    }

    #[Override]
    protected function getPageTitle(): string
    {
        return 'ImageJ Studio';
    }
}
