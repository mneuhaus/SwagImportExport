<?php

namespace Shopware\Components\SwagImportExport\DbAdapters;

class ArticlesImagesDbAdapter implements DataDbAdapter
{

    /**
     * Shopware\Components\Model\ModelManager
     */
    protected $manager;
    protected $articleRepository;
    protected $articleDetailRepository;
    protected $db;

    /**
     * Returns record ids
     * 
     * @param int $start
     * @param int $limit
     * @param type $filter
     * @return array
     */
    public function readRecordIds($start = null, $limit = null, $filter = null)
    {
        $manager = $this->getManager();

        $builder = $manager->createQueryBuilder();

        $builder->select('image.id');

        $builder->from('Shopware\Models\Article\Image', 'image')
                ->where('image.articleDetailId IS NULL')
                ->andWhere('image.parentId IS NULL');

        $builder->setFirstResult($start)
                ->setMaxResults($limit);

        $records = $builder->getQuery()->getResult();

        $result = array();
        if ($records) {
            foreach ($records as $value) {
                $result[] = $value['id'];
            }
        }

        return $result;
    }

    /**
     * Returns article images 
     * 
     * @param array $ids
     * @param array $columns
     * @return array
     */
    public function read($ids, $columns)
    {
        if (!$ids && empty($ids)) {
            throw new \Exception('Can not read article images without ids.');
        }

        if (!$columns && empty($columns)) {
            throw new \Exception('Can not read article images without column names.');
        }

        $manager = $this->getManager();

        $builder = $manager->createQueryBuilder();
        $builder->select($columns)
                ->from('Shopware\Models\Article\Image', 'aimage')
                ->innerJoin('aimage.article', 'article')
                ->innerJoin('article.details', 'articleDetail')
                ->leftJoin('Shopware\Models\Article\Detail', 'mv', \Doctrine\ORM\Query\Expr\Join::WITH, 'mv.articleId=article.id AND mv.kind=1')
                ->leftJoin('aimage.mappings', 'im')
                ->leftJoin('im.rules', 'mr')
                ->leftJoin('mr.option', 'co')
                ->leftJoin('co.group', 'cg')
                ->where('aimage.id IN (:ids)')
                ->groupBy('aimage.id')
                ->setParameter('ids', $ids);

        $result['default'] = $builder->getQuery()->getResult();

        foreach ($result['default'] as &$image) {
            if (empty($image['relations'])) {
                continue;
            }

            $relations = explode(',', $image['relations']);
            $relations = array_unique($relations);

            $out = array();
            foreach ($relations as $rule) {
                $split = explode('|', $rule);
                $ruleId = $split[0];
                $optionId = $split[1];
                $name = $split[2];
                $groupName = $split[3];

                $out[$ruleId][] = "$groupName:$name";
            }

            $temp = array();
            foreach ($out as $group) {
                $name = $group['name'];
                $temp [] = "{" . implode(',', $group) . "}";
            }

            $image['relations'] = implode('&', $temp);
        }

        return $result;
    }

    /**
     * Returns default image columns name 
     * 
     * @return array
     */
    public function getDefaultColumns()
    {
        $request = Shopware()->Front()->Request();
        $path = $request->getScheme() . '://' . $request->getHttpHost() . $request->getBasePath() . '/media/image/';

        $columns = array(
            'mv.number as ordernumber',
            "CONCAT('$path', aimage.path, '.', aimage.extension) as image",
            'aimage.main as main',
            'aimage.description as description',
            'aimage.position as position',
            'aimage.width as width',
            'aimage.height as height',
            "GroupConcat(im.id, '|', mr.optionId, '|' , co.name, '|', cg.name) as relations"
        );

        return $columns;
    }

    /**
     * Insert/Update data into db
     * 
     * @param array $records
     */
    public function write($records)
    {
        $configuratorGroupRepository = $this->getManager()->getRepository('Shopware\Models\Article\Configurator\Group');
        $configuratorOptionRepository = $this->getManager()->getRepository('Shopware\Models\Article\Configurator\Option');

        foreach ($records['default'] as $record) {
            if (empty($record['ordernumber']) || empty($record['image'])) {
                throw new \Exception('Ordernumber and image are required');
            }

            /** @var \Shopware\Models\Article\Detail $articleDetailModel */
            $articleDetailModel = $this->getArticleDetailRepository()->findOneBy(array('number' => $record['ordernumber']));
            if (!$articleDetailModel) {
                throw new \Exception(sprintf('Article with number %s does not exists', $record['ordernumber']));
            }

            if (isset($record['relations']) && !empty($record['relations'])) {
                $relations = array();
                $results = explode("&", $record['relations']);

                $i = 0;
                foreach ($results as $result) {
                    if ($result !== "") {
                        $result = preg_replace('/{|}/', '', $result);

                        foreach (explode(',', $result) as $value) {
                            list($group, $option) = explode(":", $value);

                            // Try to get given configurator group/option. Continue, if they don't exist
                            $cGroupModel = $configuratorGroupRepository->findOneBy(array('name' => $group));
                            if ($cGroupModel === null) {
                                continue;
                            }
                            $cOptionModel = $configuratorOptionRepository->findOneBy(
                                    array('name' => $option,
                                        'groupId' => $cGroupModel->getId()
                                    )
                            );
                            if ($cOptionModel === null) {
                                continue;
                            }
                            $relations[$i][] = array("group" => $cGroupModel, "option" => $cOptionModel);
                            unset($cGroupModel);
                            unset($cOptionModel);
                        }
                        $i++;
                    }
                }
            }

            /** @var \Shopware\Models\Article\Article $article */
            $article = $articleDetailModel->getArticle();

            $name = pathinfo($record['image'], PATHINFO_FILENAME);
            $path = $this->load($record['image'], $name);

            $file = new \Symfony\Component\HttpFoundation\File\File($path);

            $media = new \Shopware\Models\Media\Media();
            $media->setAlbumId(-1);
            $media->setAlbum($this->getManager()->find('Shopware\Models\Media\Album', -1));

            $media->setFile($file);
            $media->setName(pathinfo($record['image'], PATHINFO_FILENAME));
            $media->setDescription('');
            $media->setCreated(new \DateTime());
            $media->setUserId(0);

            $this->getManager()->persist($media);
            $this->getManager()->flush();

            if (empty($record['main'])) {
                $record['main'] = 1;
            }

            //generate thumbnails
            if ($media->getType() == \Shopware\Models\Media\Media::TYPE_IMAGE) {
                /*                 * @var $manager \Shopware\Components\Thumbnail\Manager */
                $manager = Shopware()->Container()->get('thumbnail_manager');
                $manager->createMediaThumbnail($media, array(), true);
            }

            $image = new \Shopware\Models\Article\Image();
            $image->setArticle($article);
            $image->setDescription($record['description']);
            $image->setPosition($record['position']);
            $image->setPath($media->getName());
            $image->setExtension($media->getExtension());
            $image->setMedia($media);
            $image->setMain($record['main']);

            $this->getManager()->persist($image);
            $this->getManager()->flush($image);

            if ($relations && !empty($relations)) {
                $this->setImageMappings($relations, $image->getId());
            }

            // Prevent multiple images from being a preview
            if ((int) $record['main'] === 1) {
                $this->getDb()->update('s_articles_img', array('main' => 2), array(
                    'articleID = ?' => $article->getId(),
                    'id <> ?' => $image->getId()
                        )
                );
            }

            unset($media);
            unset($image);
        }
    }

    /**
     * Sets image mapping for variants
     * 
     * @param array $relationGroups
     * @param int $imageId
     */
    protected function setImageMappings($relationGroups, $imageId)
    {
        foreach ($relationGroups as $relationGroup) {
            foreach ($relationGroup as $relation) {
                $optionModel = $relation['option'];

                if (!$mappingID) {
                    $this->getDb()->insert('s_article_img_mappings', array(
                        'image_id' => $imageId
                    ));
                    $mappingID = $this->getDb()->lastInsertId();
                }

                $this->getDb()->insert('s_article_img_mapping_rules', array(
                    'mapping_id' => $mappingID,
                    'option_id' => $optionModel->getId()
                ));
            }
            unset($mappingID);
        }
    }

    /**
     * @return array
     */
    public function getSections()
    {
        return array(
            array('id' => 'default', 'name' => 'default')
        );
    }

    /**
     * @param string $section
     * @return mix
     */
    public function getColumns($section)
    {
        $method = 'get' . ucfirst($section) . 'Columns';

        if (method_exists($this, $method)) {
            return $this->{$method}();
        }

        return false;
    }

    /**
     * Returns entity manager
     * 
     * @return Shopware\Components\Model\ModelManager
     */
    public function getManager()
    {
        if ($this->manager === null) {
            $this->manager = Shopware()->Models();
        }

        return $this->manager;
    }

    /**
     * Helper function to get access to the articleDetail repository.
     * @return \Shopware\Components\Model\ModelRepository
     */
    public function getArticleDetailRepository()
    {
        if ($this->articleDetailRepository === null) {
            $this->articleDetailRepository = $this->getManager()->getRepository('Shopware\Models\Article\Detail');
        }
        return $this->articleDetailRepository;
    }

    /**
     * Helper function to get access to the article repository.
     * @return Shopware\Models\Article\Repository
     */
    public function getArticleRepository()
    {
        if ($this->articleRepository === null) {
            $this->articleRepository = $this->getManager()->getRepository('Shopware\Models\Article\Article');
        }
        return $this->articleRepository;
    }

    /**
     * Helper function to get access to the datebase.
     */
    public function getDb()
    {
        if ($this->db === null) {
            $this->db = Shopware()->Db();
        }
        return $this->db;
    }

    /**
     * @param string $url URL of the resource that should be loaded (ftp, http, file)
     * @param string $baseFilename Optional: Instead of creating a hash, create a filename based on the given one
     * @return bool|string returns the absolute path of the downloaded file
     * @throws \InvalidArgumentException
     * @throws \Exception
     */
    protected function load($url, $baseFilename = null)
    {
        $destPath = Shopware()->DocPath('media_' . 'temp');
        if (!is_dir($destPath)) {
            mkdir($destPath, 0777, true);
        }

        $destPath = realpath($destPath);

        if (!file_exists($destPath)) {
            throw new \InvalidArgumentException(
            sprintf("Destination directory '%s' does not exist.", $destPath)
            );
        } elseif (!is_writable($destPath)) {
            throw new \InvalidArgumentException(
            sprintf("Destination directory '%s' does not have write permissions.", $destPath)
            );
        }

        $urlArray = parse_url($url);
        $urlArray['path'] = explode("/", $urlArray['path']);
        switch ($urlArray['scheme']) {
            case "ftp":
            case "http":
            case "https":
            case "file":
                $counter = 1;
                if ($baseFilename === null) {
                    $filename = md5(uniqid(rand(), true));
                } else {
                    $filename = $baseFilename;
                }

                while (file_exists("$destPath/$filename")) {
                    if ($baseFilename) {
                        $filename = "$counter-$baseFilename";
                        $counter++;
                    } else {
                        $filename = md5(uniqid(rand(), true));
                    }
                }

                if (!$put_handle = fopen("$destPath/$filename", "w+")) {
                    throw new \Exception("Could not open $destPath/$filename for writing");
                }

                if (!$get_handle = fopen($url, "r")) {
                    throw new \Exception("Could not open $url for reading");
                }
                while (!feof($get_handle)) {
                    fwrite($put_handle, fgets($get_handle, 4096));
                }
                fclose($get_handle);
                fclose($put_handle);

                return "$destPath/$filename";
        }
        throw new \InvalidArgumentException(
        sprintf("Unsupported schema '%s'.", $urlArray['scheme'])
        );
    }

}