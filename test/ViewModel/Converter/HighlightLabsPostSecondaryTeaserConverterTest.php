<?php

namespace test\eLife\Journal\ViewModel\Converter;

use eLife\ApiSdk\Model\Highlight;
use eLife\ApiSdk\Model\LabsPost;
use eLife\ApiSdk\Model\Model;
use eLife\Journal\ViewModel\Converter\HighlightLabsPostSecondaryTeaserConverter;
use eLife\Journal\ViewModel\Converter\ViewModelConverter;
use eLife\Patterns\ViewModel;
use Traversable;

final class HighlightLabsPostSecondaryTeaserConverterTest extends ModelConverterTestCase
{
    protected $models = ['highlight'];
    protected $viewModelClasses = [ViewModel\Teaser::class];
    protected $context = ['variant' => 'secondary'];

    /**
     * @before
     */
    public function setUpConverter()
    {
        $this->converter = new HighlightLabsPostSecondaryTeaserConverter(
            $viewModelConverter = $this->createMock(ViewModelConverter::class),
            $this->stubUrlGenerator()
        );

        $viewModelConverter
            ->expects($this->any())
            ->method('convert')
            ->will($this->returnValue(new ViewModel\Picture(
                [],
                new ViewModel\Image('/image.jpg')
            )));
    }

    /**
     * @param Highlight $model
     */
    protected function modelHook(Model $model) : Traversable
    {
        if ($model->getItem() instanceof LabsPost) {
            yield $model;
        }
    }
}
