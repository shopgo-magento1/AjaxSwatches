<?php
/**
 * Class Mage_ConfigurableSwatches_Helper_Productimg
 */
class Wigman_AjaxSwatches_Helper_Productimg extends Mage_ConfigurableSwatches_Helper_Productimg
{
    /**
     * Color swatches image types (file extensions),
     * that could be checked
     *
     * @var array
     */
    protected $swatchFileExtensions = array(
        '.png',
        '.jpg',
        '.jpeg',
        '.gif'
    );

    /**
     * Create the separated index of product images
     *
     * @param Mage_Catalog_Model_Product $product
     * @param array|null $preValues
     * @return Mage_ConfigurableSwatches_Helper_Data
     *
     * Wigman Changes:
     * - we are now hiding products that sold out if this is set in admin (cataloginventory/options/show_out_of_stock), so we're missing these labels and skip those swatches accordingly in indexProductImages()
     * - we changed the labels object to include sort_ids and need to adjust the references in filterImageInGallery()
     *
     */
    public function indexProductImages($product, $preValues = null)
    {
        if ($product->getTypeId() != Mage_Catalog_Model_Product_Type::TYPE_CONFIGURABLE) {
            return; // we only index images on configurable products
        }

        if (!isset($this->_productImagesByLabel[$product->getId()])) {
            $images = array();
            $searchValues = array();

            if (!is_null($preValues) && is_array($preValues)) { // If a pre-defined list of valid values was passed
                $preValues = array_map('Mage_ConfigurableSwatches_Helper_Data::normalizeKey', $preValues);
                foreach ($preValues as $value) {
                    $searchValues[] = $value;
                }
            } else { // we get them from all config attributes if no pre-defined list is passed in
                $attributes = $product->getTypeInstance(true)->getConfigurableAttributes($product);

                // Collect valid values of image type attributes
                foreach ($attributes as $attribute) {
                    if (Mage::helper('configurableswatches')->attrIsSwatchType($attribute->getAttributeId())) {
                        foreach ($attribute->getPrices() as $option) { // getPrices returns info on individual options
                            $searchValues[] = Mage_ConfigurableSwatches_Helper_Data::normalizeKey($option['label']);
                        }
                    }
                }
            }

            $mapping = $product->getChildAttributeLabelMapping();
            $mediaGallery = $product->getMediaGallery();
            $mediaGalleryImages = $product->getMediaGalleryImages();

            if (empty($mediaGallery['images']) || empty($mediaGalleryImages)) {
                $this->_productImagesByLabel[$product->getId()] = array();
                return; //nothing to do here
            }

            $imageHaystack = array_map(function ($value) {
                return Mage_ConfigurableSwatches_Helper_Data::normalizeKey($value['label']);
            }, $mediaGallery['images']);

            foreach ($searchValues as $label) {
                $imageKeys = array();
                $swatchLabel = $label . self::SWATCH_LABEL_SUFFIX;

                if(isset($mapping[$label])){ //wigman add-on - after skipping the !isSalable colors, we don't have all the labels available

                    $imageKeys[$label] = array_search($label, $imageHaystack);
                    if ($imageKeys[$label] === false) {
                        $imageKeys[$label] = array_search($mapping[$label]['default_label'], $imageHaystack);
                    }

                    $imageKeys[$swatchLabel] = array_search($swatchLabel, $imageHaystack);
                    if ($imageKeys[$swatchLabel] === false) {
                        $imageKeys[$swatchLabel] = array_search(
                            $mapping[$label]['default_label'] . self::SWATCH_LABEL_SUFFIX, $imageHaystack
                        );
                    }

                    foreach ($imageKeys as $imageLabel => $imageKey) {
                        if ($imageKey !== false) {
                            $imageId = $mediaGallery['images'][$imageKey]['value_id'];
                            $images[$imageLabel] = $mediaGalleryImages->getItemById($imageId);
                        }
                    }
                }
            }
            $this->_productImagesByLabel[$product->getId()] = $images;
        }
    }

    /**
     * Return URL for a matching swatch image from the global directory
     *
     * @param Mage_Catalog_Model_Product|Mage_Catalog_Model_Layer_Filter_Item $object
     * @param string $value
     * @param int $width
     * @param int $height
     * @param string $fileExt
     * @throws Mage_Core_Exception
     * @return string
     */
    public function getGlobalSwatchUrl($object, $value, $width = self::SWATCH_DEFAULT_WIDTH,
        $height = self::SWATCH_DEFAULT_HEIGHT, $fileExt = null
    ) {
        if (is_null($fileExt)) {
            $fileExt = $this->swatchFileExtensions;
        }

        // normalize to all lower case so that value can be used as array key below
        $value = Mage_ConfigurableSwatches_Helper_Data::normalizeKey($value);
        $defaultValue = $value; // default to no fallback value
        if ($object instanceof Mage_Catalog_Model_Layer_Filter_Item) { // fallback for swatches loaded for nav filters
            $source = $object->getFilter()->getAttributeModel()->getFrontend()->getAttribute()->getSource();
            foreach ($source->getAllOptions(false, true) as $option) {
                if ($option['value'] == $object->getValue()) {
                    $defaultValue = Mage_ConfigurableSwatches_Helper_Data::normalizeKey($option['label']);
                    break;
                }
            }
        } elseif ($object instanceof Mage_Catalog_Model_Product) {  // fallback for swatches loaded for product view
            $mapping = $object->getChildAttributeLabelMapping();
            if (isset($mapping[$value]['default_label'])) {
                $defaultValue = $mapping[$value]['default_label'];
            }
        }

        do {
            $filename = Mage::helper('configurableswatches')->getHyphenatedString($value);
            if (is_array($fileExt)) {
                foreach ($fileExt as $ext) {
                    // Only 1 file extension can be used,
                    // So, if there is a filename with multiple
                    // extensions, only the first checked file extension
                    // will be used
                    $swatchImage = $this->_resizeSwatchImage($filename . $ext, 'media', $width, $height);
                    if ($swatchImage) {
                        break;
                    }
                }
            } else {
                $swatchImage = $this->_resizeSwatchImage($filename . $fileExt, 'media', $width, $height);
            }
            if (!$swatchImage && $defaultValue == $value) {
                return '';  // no image found and no further fallback
            } elseif (!$swatchImage) {
                $value = $defaultValue; // fallback to default value
            } else {
                break;  // we found an image
            }
        } while (true);

        return Mage::getBaseUrl(Mage_Core_Model_Store::URL_TYPE_MEDIA) . $swatchImage;
    }

    /**
     * Determine whether to show an image in the product media gallery
     *
     * @param Mage_Catalog_Model_Product $product
     * @param Varien_Object $image
     * @return bool
     */
    public function filterImageInGallery($product, $image)
    {
        if (!Mage::helper('configurableswatches')->isEnabled()) {
            return true;
        }

        if (!isset($this->_productImageFilters[$product->getId()])) {
            $mapping = call_user_func_array("array_merge_recursive", $product->getChildAttributeLabelMapping());

            /* Wigman: this goes out: */
            //$filters = array_unique($mapping['labels']);

            /* Wigman: and this comes in (that's because we changed the labels object to include sort_ids) */
            $filters = array();
            foreach($mapping['labels'] as $index => $label){
                $filters[$index] = $label['label'];
            }

            $filters = array_unique($filters);


            $filters = array_merge($filters, array_map(function ($label) {
                return $label . Mage_ConfigurableSwatches_Helper_Productimg::SWATCH_LABEL_SUFFIX;
            }, $filters));
            $this->_productImageFilters[$product->getId()] = $filters;
        }

        return !in_array(Mage_ConfigurableSwatches_Helper_Data::normalizeKey($image->getLabel()),
            $this->_productImageFilters[$product->getId()]);
    }
}
