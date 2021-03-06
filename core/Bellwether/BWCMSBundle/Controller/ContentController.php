<?php

namespace Bellwether\BWCMSBundle\Controller;

use Bellwether\BWCMSBundle\Classes\Base\BaseController;
use Bellwether\BWCMSBundle\Classes\Base\BackEndControllerInterface;
use Bellwether\BWCMSBundle\Classes\Constants\AuditLevelType;
use Bellwether\BWCMSBundle\Classes\Constants\ContentPublishType;
use Bellwether\BWCMSBundle\Classes\Constants\ContentSortByType;
use Bellwether\BWCMSBundle\Classes\Constants\ContentSortOrderType;
use Bellwether\BWCMSBundle\Classes\Constants\ContentScopeType;
use Doctrine\Common\Proxy\Exception\InvalidArgumentException;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Security;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Bellwether\BWCMSBundle\Entity\ContentEntity;
use Bellwether\BWCMSBundle\Entity\ContentMediaEntity;
use Bellwether\BWCMSBundle\Classes\Content\ContentType;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\Form\FormError;

/**
 * Page controller.
 *
 * @Route("/admin/manage")
 * @Security("has_role('ROLE_AUTHOR')")
 */
class ContentController extends BaseController implements BackEndControllerInterface
{
    /**
     * @Route("/{type}/index.php",name="_bwcms_admin_content_home")
     * @Template()
     */
    public function indexAction(Request $request)
    {
        $type = $request->get('type', 'Content');
        $parentId = $request->get('parent', 'Root');

        $registeredContents = $this->cm()->getRegisteredContentTypes($type);
        $mediaContentTypes = $this->cm()->getMediaContentTypes($type);

        $jsNodes = $this->getFolderTree($type, $parentId);

        return array(
            'parentId' => $parentId,
            'type' => $type,
            'scope' => ContentScopeType::CPublic,
            'title' => ucfirst($type) . ' Manager',
            'contentTypes' => $registeredContents,
            'mediaContentTypes' => $mediaContentTypes,
            'jsNodes' => json_encode($jsNodes),
        );
    }

    /**
     * @Route("/create.php",name="_bwcms_admin_content_create")
     * @Template("BWCMSBundle:Content:save.html.twig")
     */
    public function createAction(Request $request)
    {

        $type = $request->get('type', null);
        $schema = $request->get('schema', null);
        $parent = $request->get('parent', null);
        $scope = $request->get('scope', ContentScopeType::CPublic);

        if (is_null($type) || is_null($schema) || is_null($parent)) {
            return $this->returnErrorResponse();
        }
        if ($scope != ContentScopeType::CPublic && $scope != ContentScopeType::CPrivate && $scope != ContentScopeType::CPageBuilder) {
            return $this->returnErrorResponse();
        }

        $class = $this->cm()->getContentClass($type, $schema);
        if ($parent != 'Root') {
            $class->setParent($parent);
        }
        $form = $class->getForm();
        $content = $class->getNewContent();
        $content->setScope($scope);
        $form = $this->cm()->loadFormData($content, $form, $class);

        return array(
            'title' => 'Create ' . $class->getName(),
            'form' => $form->createView()
        );
    }

    /**
     * @Route("/edit.php",name="_bwcms_admin_content_edit")
     * @Template("BWCMSBundle:Content:save.html.twig")
     */
    public function editAction(Request $request)
    {
        $contentId = $request->get('contentId');
        if ($contentId == null) {
            return $this->returnErrorResponse();
        }
        /**
         * @var \Bellwether\BWCMSBundle\Entity\ContentEntity $content
         */
        $content = $this->cm()->getContentRepository()->find($contentId);
        if ($content == null) {
            return $this->returnErrorResponse();
        }

        $class = $this->cm()->getContentClass($content->getType(), $content->getSchema());
        if ($content->getTreeParent() != null) {
            $class->setParent($content->getTreeParent()->getId());
        }
        $form = $class->getForm(true, $content);
        $form = $this->cm()->loadFormData($content, $form, $class);

        return array(
            'title' => 'Edit ' . $class->getName(),
            'form' => $form->createView()
        );
    }


    /**
     * @Route("/pb.php",name="_bwcms_admin_content_pb")
     * @Template()
     */
    public function pageBuilderAction(Request $request)
    {
        $contentId = $request->get('contentId');
        if ($contentId == null) {
            return $this->returnErrorResponse();
        }
        /**
         * @var \Bellwether\BWCMSBundle\Entity\ContentEntity $content
         */
        $content = $this->cm()->getContentRepository()->find($contentId);
        if ($content == null) {
            return $this->returnErrorResponse();
        }

        $qb = $this->cm()->getContentRepository()->getChildrenQueryBuilder($content, false);
        $registeredContents = $this->cm()->getRegisteredContentTypes('Widget');
        $condition = array();
        foreach ($registeredContents as $cInfo) {
            $condition[] = " (node.type = '" . $cInfo['type'] . "' AND node.schema = '" . $cInfo['schema'] . "' )";
        }
        if (!empty($condition)) {
            $qb->andWhere(' ( ' . implode(' OR ', $condition) . ' ) ');
        }
        $qb->andWhere(" node.site ='" . $this->sm()->getAdminCurrentSite()->getId() . "' ");
        $qb->andWhere(" node.scope ='" . ContentScopeType::CPageBuilder . "' ");
        $pageContents = $qb->getQuery()->getResult();

        $jsNodes = array(
            array(
                'id' => $content->getId(),
                'text' => $content->getTitle(),
                'icon' => 'glyphicon glyphicon-folder-open',
                'parent' => '#',
                'state' => array(
                    'opened' => true
                )
            )
        );
        if (!empty($pageContents)) {
            /** @var ContentEntity $pContent */
            foreach ($pageContents as $pContent) {
                $jsNode = array();
                $jsNode['id'] = $pContent->getId();
                $jsNode['text'] = $pContent->getTitle();
                $class = $this->cm()->getContentClass($pContent->getType(), $pContent->getSchema());
                if ($class->isHierarchy()) {
                    $jsNode['icon'] = 'glyphicon glyphicon-folder-open';
                } else {
                    $jsNode['icon'] = 'glyphicon glyphicon-file';
                }
                $jsNode['parent'] = $pContent->getTreeParent()->getId();
                $jsNode['state'] = array(
                    'opened' => true,
                );
                $jsNodes[] = $jsNode;
            }
        }

        return array(
            'title' => 'Page Builder',
            'pageTitle' => 'Page Builder',
            'scope' => ContentScopeType::CPageBuilder,
            'contentTypes' => $registeredContents,
            'jsNodes' => json_encode($jsNodes),
        );
    }

    /**
     * @Route("/save.php",name="_bwcms_admin_content_save")
     * @Method({"POST"})
     * @Template("BWCMSBundle:Content:save.html.twig")
     */
    public function saveAction(Request $request)
    {

        $contentId = $request->query->get('contentId');
        $contentType = $request->query->get('contentType');
        $contentSchema = $request->query->get('contentSchema');
        if (is_null($contentType) || is_null($contentSchema)) {
            return $this->returnErrorResponse();
        }

        $editingContent = null;
        $isEditMode = false;
        if (!is_null($contentId)) {
            /**
             * @var \Bellwether\BWCMSBundle\Entity\ContentEntity $content
             */
            $editingContent = $this->cm()->getContentRepository()->find($contentId);
            if ($editingContent == null) {
                return $this->returnErrorResponse();
            }
            $isEditMode = true;
        }

        /**
         * @var ContentType $class
         */
        $class = $this->cm()->getContentClass($contentType, $contentSchema);
        $form = $class->getForm($isEditMode, $editingContent);
        $form->handleRequest($request);

        if ($form->isValid()) {
            if (is_null($editingContent)) {
                $editingContent = new ContentEntity();
                $editingContent->setSite($this->getSite());
            }

            try {
                $editingContent = $this->cm()->prepareEntity($editingContent, $form, $class);
                $this->cm()->save($editingContent);

                $qb = $this->em()->createQueryBuilder();
                $queryResult = $qb->select(array('s3'))
                    ->from('BWCMSBundle:S3QueueEntity', 's3')
                    ->andWhere($qb->expr()->eq('s3.content', $qb->expr()->literal($editingContent->getId())))
                    ->getQuery()
                    ->getResult();
                if (!empty($queryResult)) {
                    foreach ($queryResult as $deleteItem) {
                        $this->em()->remove($deleteItem);
                    }
                    $this->em()->flush();
                }

                if ($editingContent->getScope() == ContentScopeType::CPageBuilder) {
                    $parents = $this->cm()->getContentRepository()->getPath($editingContent);
                    $parents = array_reverse($parents);
                    foreach ($parents as $parent) {
                        if ($parent->getScope() == ContentScopeType::CPublic) {
                            return $this->redirect($this->generateUrl('_bwcms_admin_content_pb', array('contentId' => $parent->getId())));
                        }
                    }
                    return $this->returnErrorResponse();
                }

                $parentId = 'Root';
                if ($editingContent->getTreeParent() != null) {
                    $parentId = $editingContent->getTreeParent()->getId();
                }
                if ($class->isTaxonomy()) {
                    return $this->redirect($this->generateUrl('_bwcms_admin_taxonomy_home', array('schema' => $contentSchema, 'parent' => $parentId)));
                }
                return $this->redirect($this->generateUrl('_bwcms_admin_content_home', array('type' => $contentType, 'parent' => $parentId)));
            } catch (\Exception $e) {
                if ($e instanceof FileException) {
                    $form->get('attachment')->addError(new FormError($e->getMessage()));
                } else {
                    $form->addError(new FormError($e->getMessage()));
                }
            }
        }

        return array(
            'title' => 'Edit ' . $class->getName(),
            'pageTitle' => 'Edit ' . $class->getName(),
            'form' => $form->createView()
        );
    }

    function getFolderTree($type, $parentId, $schema = null, $rootFolderCaption = 'Folders', $scope = ContentScopeType::CPublic)
    {
        $qb = $this->cm()->getContentRepository()->getChildrenQueryBuilder(null, false);
        $registeredContents = $this->cm()->getRegisteredContentTypes($type, $schema);
        $condition = array();
        foreach ($registeredContents as $cInfo) {
            $class = $cInfo['class'];
            if ($class->isHierarchy()) {
                $condition[] = " (node.type = '" . $cInfo['type'] . "' AND node.schema = '" . $cInfo['schema'] . "' )";
            }
        }
        if (!empty($condition)) {
            $qb->andWhere(' ( ' . implode(' OR ', $condition) . ' ) ');
        }
        $qb->andWhere(" node.site ='" . $this->sm()->getAdminCurrentSite()->getId() . "' ");
        $qb->andWhere(" node.scope ='" . ContentScopeType::CPublic . "' ");
        $qb->add('orderBy', 'node.title ASC');

        $rootFolders = $qb->getQuery()->getResult();

        $jsNodes = array(
            array(
                'id' => 'Root',
                'text' => $rootFolderCaption,
                'sort' => 0,
                'icon' => 'glyphicon glyphicon-folder-open',
                'parent' => '#',
                'state' => array(
                    'opened' => true,
                    'selected' => ($parentId == 'Root')
                )
            )
        );
        if (!empty($rootFolders)) {
            /** @var ContentEntity $content */
            foreach ($rootFolders as $content) {
                $jsNode = array();
                $jsNode['id'] = $content->getId();
                $jsNode['text'] = $content->getTitle();
                if ($content->getSortBy() == ContentSortByType::SortIndex) {
                    $jsNode['sort'] = 1;
                } else {
                    $jsNode['sort'] = 0;
                }
                $jsNode['icon'] = 'glyphicon glyphicon-folder-open';
                if ($content->getTreeParent() != null) {
                    $jsNode['parent'] = $content->getTreeParent()->getId();
                } else {
                    $jsNode['parent'] = 'Root';
                }
                if ($parentId == $content->getId()) {
                    $jsNode['state'] = array(
                        'opened' => true,
                        'selected' => true
                    );
                }
                $jsNodes[] = $jsNode;
            }
        }
        return $jsNodes;
    }

    /**
     * @Route("/taxonomy.php",name="_bwcms_admin_taxonomy_home")
     * @Template()
     */
    public function taxonomyAction(Request $request)
    {
        $type = 'Taxonomy';
        $schema = $request->get('schema');
        $start = $request->get('start', 0);
        $length = $request->get('length', 10);

        if (is_null($schema)) {
            throw new \InvalidArgumentException('Invalid Schema');
        }
        $taxonomyClass = $this->cm()->getContentClass($type, $schema);
        if (empty($taxonomyClass)) {
            throw new \InvalidArgumentException('Invalid Schema');
        }

        $isHierarchy = $taxonomyClass->isHierarchy();
        $returnVars = array(
            'type' => 'Taxonomy',
            'isHierarchy' => $isHierarchy,
            'schema' => $schema,
            'title' => $taxonomyClass->getName() . ' Manager',
        );


        if ($isHierarchy) {
            $jsNodes = $this->getFolderTree($type, 'Root', $schema, $taxonomyClass->getName());
            $returnVars['jsNodes'] = json_encode($jsNodes);
        } else {
            /**
             * Get All the root folders
             * @var \Bellwether\BWCMSBundle\Entity\ContentEntity $content
             * @var \Bellwether\BWCMSBundle\Entity\ContentEntity $parentFolder
             */
            $uiSortEnabled = false;
            $contentRepository = $this->cm()->getContentRepository();
            $qb = $contentRepository->getChildrenQueryBuilder(null, true);
            $qb->andWhere(" (node.type = '" . $taxonomyClass->getType() . "' AND node.schema = '" . $taxonomyClass->getSchema() . "' ) ");
            $qb->andWhere(" node.site ='" . $this->sm()->getAdminCurrentSite()->getId() . "' ");
            $qb->setFirstResult($start);
            $qb->setMaxResults($length);

            $returnVars['entities'] = $qb->getQuery()->getResult();

            $qb2 = clone $qb; // don't modify existing query
            $qb2->resetDQLPart('orderBy');
            $qb2->resetDQLPart('having');
            $qb2->select('COUNT(node) AS cnt');
            $countResult = $qb2->getQuery()->setFirstResult(0)->getScalarResult();
            $returnVars['totalCount'] = $countResult[0]['cnt'];
        }

        return $returnVars;

    }

    /**
     * @Route("/browser.php",name="_bwcms_admin_content_browser")
     * @Template()
     */
    public function browserAction(Request $request)
    {

        $type = $request->get('type', 'Content');
        $parentId = $request->get('parent', 'Root');
        $holder = $request->get('holder', '');
        $schema = $request->get('schema', '');
        $extension = $request->get('extension', '');
        $onlyImage = $request->get('onlyImage');
        $selectedContentId = $request->get('selectedContentId', null);

        if (is_null($onlyImage)) {
            $onlyImage = 'no';
        } else {
            $onlyImage = 'yes';
        }


        $contentRepo = $this->cm()->getContentRepository();
        $selectContentPath = null;
        if (!is_null($selectedContentId)) {
            $selectedContent = $contentRepo->find($selectedContentId);
            if (!is_null($selectedContent)) {
                $selectContentPath = $contentRepo->getPath($selectedContent);
                if (count($selectContentPath) > 1) {
                    $parentId = $selectContentPath[count($selectContentPath) - 2]->getId();
                }
            }
        }

        $qb = $contentRepo->getChildrenQueryBuilder(null, false);
        $registeredContents = $this->cm()->getRegisteredContentTypes($type);
        $condition = array();
        foreach ($registeredContents as $cInfo) {
            if ($cInfo['isHierarchy']) {
                $condition[] = " (node.type = '" . $cInfo['type'] . "' AND node.schema = '" . $cInfo['schema'] . "' )";
            }
        }
        if (!empty($condition)) {
            $qb->andWhere(' ( ' . implode(' OR ', $condition) . ' ) ');
        }
        $qb->andWhere(" node.site ='" . $this->sm()->getAdminCurrentSite()->getId() . "' ");
        $qb->add('orderBy', 'node.title ASC');

        $rootFolders = $qb->getQuery()->getResult();

        $jsNodes = array(
            array(
                'id' => 'Root',
                'text' => 'Folders',
                'icon' => 'glyphicon glyphicon-folder-open',
                'parent' => '#',
                'state' => array(
                    'opened' => true,
                    'selected' => ($parentId == 'Root')
                )
            )
        );
        if (!empty($rootFolders)) {
            /** @var ContentEntity $content */
            foreach ($rootFolders as $content) {
                $jsNode = array();
                $jsNode['id'] = $content->getId();
                $jsNode['text'] = $content->getTitle();
                $jsNode['icon'] = 'glyphicon glyphicon-folder-open';
                if ($content->getTreeParent() != null) {
                    $jsNode['parent'] = $content->getTreeParent()->getId();
                } else {
                    $jsNode['parent'] = 'Root';
                }
                if ($parentId == $content->getId()) {
                    $jsNode['state'] = array(
                        'opened' => true,
                        'selected' => true
                    );
                }
                $jsNodes[] = $jsNode;
            }
        }

        return array(
            'parentId' => $parentId,
            'holder' => $holder,
            'type' => $type,
            'onlyImage' => $onlyImage,
            'extension' => $extension,
            'schema' => $schema,
            'selectContentPath' => $selectContentPath,
            'contentTypes' => $this->cm()->getRegisteredContentTypes(),
            'jsNodes' => json_encode($jsNodes),
        );

    }


    /**
     * @Route("/upload.php",name="_bwcms_admin_content_media_upload")
     * @Method({"POST"})
     */
    public function uploadAction(Request $request)
    {
        $parentId = $request->get('parent', null);
        $type = $request->get('type', null);
        $schema = $request->get('schema', null);

        if (is_null($type) || is_null($schema) || is_null($parentId)) {
            return $this->returnErrorResponse();
        }

        try {
            $uploadedFile = $request->files->get('file');
            $mediaInfo = $this->mm()->handleUpload($uploadedFile);
        } catch (\Exception $e) {
            return new Response($e->getMessage(), 500);
        }
        if (!empty($mediaInfo)) {
            /**
             * @var \Bellwether\BWCMSBundle\Entity\ContentRepository $contentRepository
             * @var \Bellwether\BWCMSBundle\Entity\ContentEntity $content
             */
            $contentRepository = $this->em()->getRepository('BWCMSBundle:ContentEntity');
            $content = new ContentEntity();
            $content->setType($type);
            $content->setSchema($schema);
            $content->setSite($this->getSite());
            if ($parentId == 'Root') {
                $content->setTreeParent(null);
            } else {
                $parentEntity = $contentRepository->find($parentId);
                $content->setTreeParent($parentEntity);
            }
            $content->setTitle($mediaInfo['originalName']);
            $content->setTemplate('');

            $contentMedia = new ContentMediaEntity();
            $contentMedia->setFile($mediaInfo['filename']);
            $contentMedia->setExtension($mediaInfo['extension']);
            $contentMedia->setMime($mediaInfo['mimeType']);
            $contentMedia->setSize($mediaInfo['size']);
            $contentMedia->setHeight($mediaInfo['height']);
            $contentMedia->setWidth($mediaInfo['width']);
            if (!is_null($mediaInfo['binary'])) {
                $contentMedia->setData($mediaInfo['binary']);
            }
            $contentMedia->setContent($content);
            $this->em()->persist($contentMedia);

            $content->setSlug($this->cm()->generateSlug($content->getTitle(), $content->getType(), $parentId));
            $content->setStatus(ContentPublishType::Published);
            $this->cm()->save($content);
        }
        return new Response('Ok', 200);
    }

    /**
     * @Route("/data.php",name="_bwcms_admin_content_table_data")
     * @Method({"GET"})
     */
    public function indexDataAction(Request $request)
    {
        $type = $request->get('type', 'Content');
        $draw = $request->get('draw', 0);
        $start = $request->get('start', 0);
        $length = $request->get('length', 10);
        $parentId = $request->get('parent', 'Root');
        $onlyImage = strtolower($request->get('onlyImage', 'no'));
        $imageURL = $request->get('imageURL', false);
        $isEditor = $request->get('editor', false);
        $extension = $request->get('extension', '');

        set_time_limit(0);

        $search = $request->get('search');
        $searchString = null;
        $schema = $request->get('schema');
        if ($search != null && isset($search['value']) && !empty($search['value'])) {
            $searchString = $search['value'];
        }

        /**
         * Get All the root folders
         * @var \Bellwether\BWCMSBundle\Entity\ContentEntity $content
         * @var \Bellwether\BWCMSBundle\Entity\ContentEntity $parentFolder
         */
        $uiSortEnabled = false;
        $contentRepository = $this->cm()->getContentRepository();
        if ($parentId == 'Root') {
            $qb = $contentRepository->getChildrenQueryBuilder(null, true);
        } else {
            $parentFolder = $contentRepository->find($parentId);
            $qb = $contentRepository->getChildrenQueryBuilder($parentFolder, true);
            $sortOrder = ' ASC';
            if ($parentFolder->getSortOrder() == ContentSortOrderType::DESC) {
                $sortOrder = ' DESC';
            }
            if ($parentFolder->getSortBy() == ContentSortByType::SortIndex) {
                $uiSortEnabled = true;
                $qb->add('orderBy', 'node.treeLeft');
            } elseif ($parentFolder->getSortBy() == ContentSortByType::Created) {
                $qb->add('orderBy', 'node.createdDate' . $sortOrder);
            } elseif ($parentFolder->getSortBy() == ContentSortByType::Published) {
                $qb->add('orderBy', 'node.publishDate' . $sortOrder);
            } elseif ($parentFolder->getSortBy() == ContentSortByType::Title) {
                $qb->add('orderBy', 'node.title' . $sortOrder);
            } elseif ($parentFolder->getSortBy() == ContentSortByType::Size) {
                $qb->add('orderBy', 'node.size' . $sortOrder);
            }
        }

        $condition = array();
        if (strpos($schema, '::') !== false) {
            $searchSchemas = explode('::', $schema);
            if (!empty($searchSchemas)) {
                foreach ($searchSchemas as $schemaItem) {
                    $registeredContents = $this->cm()->getRegisteredContentTypes($type, $schemaItem);
                    foreach ($registeredContents as $cInfo) {
                        $condition[] = " (node.type = '" . $cInfo['type'] . "' AND node.schema = '" . $cInfo['schema'] . "' )";
                    }
                }
            }
        } else {
            $registeredContents = $this->cm()->getRegisteredContentTypes($type, $schema);
            foreach ($registeredContents as $cInfo) {
                $condition[] = " (node.type = '" . $cInfo['type'] . "' AND node.schema = '" . $cInfo['schema'] . "' )";
            }
        }

        if (!empty($condition)) {
            $qb->andWhere(' ( ' . implode(' OR ', $condition) . ' ) ');
        }

        $currentSite = $this->sm()->getAdminCurrentSite();
        $qb->andWhere(" node.site = '" . $currentSite->getId() . "' ");
        $qb->andWhere(" node.scope = '" . ContentScopeType::CPublic . "' ");

        $qb->setFirstResult($start);
        $qb->setMaxResults($length);
        if ($uiSortEnabled && !$isEditor) {
            $qb->setFirstResult(0);
            $qb->setMaxResults(99999);
        }

        if (!empty($searchString)) {
            $qb->andWhere(" ( node.title LIKE :query1 OR node.file LIKE :query2  ) ");
            $qb->setParameter('query1', '%' . $searchString . '%');
            $qb->setParameter('query2', '%' . $searchString . '%');
        }

        if ($onlyImage == 'yes') {
            $qb->leftJoin('Bellwether\BWCMSBundle\Entity\ContentMediaEntity', 'media', \Doctrine\ORM\Query\Expr\Join::WITH, ' node.id = media.content ');
            $qb->andWhere(" ( media.height > 0 AND media.width > 0 ) ");
        }

        if (!empty($extension)) {
            if ($onlyImage != 'yes') {
                $qb->leftJoin('Bellwether\BWCMSBundle\Entity\ContentMediaEntity', 'media', \Doctrine\ORM\Query\Expr\Join::WITH, ' node.id = media.content ');
            }
            $qb->andWhere(" ( media.extension = '" . strtolower($extension) . "' ) ");
        }


        $result = $qb->getQuery()->getResult();

        $qb2 = clone $qb; // don't modify existing query
        $qb2->resetDQLPart('orderBy');
        $qb2->resetDQLPart('having');
        $qb2->select('COUNT(node) AS cnt');
        $countResult = $qb2->getQuery()->setFirstResult(0)->getScalarResult();
        $totalCount = $countResult[0]['cnt'];

        $data = array();
        $data['draw'] = $draw;
        $data['sort'] = $uiSortEnabled;

        $data['recordsFiltered'] = $totalCount;
        $data['recordsTotal'] = $totalCount;
        $data['data'] = array();

        if (!empty($result)) {
            foreach ($result as $content) {
                $contentClass = $this->cm()->getContentClass($content->getType(), $content->getSchema());

                $ca = array();
                $ca['DT_RowId'] = $content->getId();
                $ca['title'] = $content->getTitle();
                $ca['name'] = $content->getFile();
                $ca['type'] = $contentClass->getName();

                switch ($content->getStatus()) {
                    case ContentPublishType::Draft:
                        $ca['status'] = 'Draft';
                        break;
                    case ContentPublishType::Published:
                        $ca['status'] = 'Published';
                        break;
                    case ContentPublishType::Expired:
                        $ca['status'] = 'Expired';
                        break;
                    case ContentPublishType::WorkFlow:
                        $ca['status'] = 'WorkFlow';
                        break;
                    default:
                        $ca['status'] = 'Custom';
                }
                $ca['createdDate'] = $content->getCreatedDate()->format('Y-m-d H:i:s');
                $ca['thumbnail'] = $this->mm()->getContentThumbURL($content, 32, 32);
                $ca['thumbnail'] = '<img class="contentThumb" src="' . $ca['thumbnail'] . '"/>';

                $ca['link'] = '';
                $contentPublicURL = $contentClass->getPublicURL($content);
                if (!is_null($contentPublicURL)) {
                    $ca['link'] = $contentPublicURL;
                }
                $ca['pbLink'] = '';
                if ($contentClass->isPageBuilderSupported()) {
                    $ca['pbLink'] = $this->generateUrl('_bwcms_admin_content_pb', array('contentId' => $content->getId()));
                }
                $ca['download'] = '';
                if ($this->mm()->isMedia($content)) {
                    $ca['type'] = $contentClass->getName() . ' (' . $this->mm()->fileSize($content) . ')';
                    $ca['imageURL'] = $this->generateUrl('media_image_view', array('contentId' => $content->getId(), 'siteSlug' => $currentSite->getSlug()));
                    $ca['imageURL'] = str_ireplace('/app_dev.php', '', $ca['imageURL']);
//                    $ca['download'] = $this->generateUrl('_bwcms_admin_content_media_download', array('contentId' => $content->getId()));
                    $ca['download'] = $this->generateUrl('media_download_link', array('contentId' => $content->getId(), 'siteSlug' => $currentSite->getSlug()));
                }
                if ($this->mm()->isImage($content)) {
                    $imageThumb = $this->mm()->getContentThumbURL($content, 800, 800);
                    $ca['thumbnail'] = '<a href="' . $imageThumb . '" data-title="' . $content->getTitle() . '" class="lightBox">' . $ca['thumbnail'] . '</a>';
                }
                $data['data'][] = $ca;
            }
        }

        return $this->returnJsonReponse($request, $data);
    }


    /**
     * @Route("/templatethumb/{type}/s/{schema}/i/{image}",name="_bwcms_admin_content_template_thumb")
     * @Method({"GET"})
     */
    public function contentTemplateThumbAction(Request $request, $type, $schema, $image)
    {
        $contentClass = $this->cm()->getContentClass($type, $schema);
        $templateImagePath = str_replace('.', DIRECTORY_SEPARATOR, $contentClass->getType() . '.' . $contentClass->getSchema());
        $templateImagePath = $contentClass->tp()->getCurrentSkin()->getPath() . DIRECTORY_SEPARATOR . $templateImagePath . DIRECTORY_SEPARATOR . $image;

        $thumb = $this->getThumbService()->open($templateImagePath)->resize(240, 200);
        $thumbCache = $thumb->cacheFile('guess', 80, true);
        $response = new BinaryFileResponse($thumbCache);
        $response->trustXSendfileTypeHeader();
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_INLINE,
            $image,
            iconv('UTF-8', 'ASCII//TRANSLIT', $image)
        );

        return $response;

    }

    /**
     * @Route("/thumb/{contentId}/w/{width}/h/{height}/file.png",name="_bwcms_admin_content_thumb")
     * @Method({"GET"})
     */
    public function contentThumbAction(Request $request, $contentId, $width, $height)
    {
        /**
         * @var ContentEntity $content
         */
        $content = $this->cm()->getContentRepository()->find($contentId);
        if (empty($content)) {
            return $this->createNotFoundException();
        }

        $imageFilename = 'dummy.jpg';
        $contentClass = $this->cm()->getContentClass($content->getType(), $content->getSchema());
        if (!$contentClass->isUploadEnabled()) {
            $imageFile = $contentClass->getImage();
            $imageFilename = basename($imageFile);
        } else {
            $media = $content->getMedia()->first();
            $this->mm()->checkAndCreateMediaCacheFile($media);
            $imageFilename = $media->getFile();
            if ($this->mm()->isImage($content)) {
                $imageFile = $this->mm()->getMediaCachePath($media);
            } else {
                $imageFile = $this->mm()->getMimeResourceImage($media->getExtension());
            }
        }


        $thumb = $this->getThumbService()->open($imageFile)->resize($width, $height);
        $thumbCache = $thumb->cacheFile('guess', 80, true);
        $response = new BinaryFileResponse($thumbCache);
        $response->trustXSendfileTypeHeader();
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_INLINE,
            $imageFilename,
            iconv('UTF-8', 'ASCII//TRANSLIT', $imageFilename)
        );

        return $response;
    }

    /**
     * @Route("/folder-move.php",name="_bwcms_admin_content_folder_move")
     * @Method({"POST"})
     */
    public function folderMoveAction(Request $request)
    {
        $contentId = $request->get('contentId');
        $targetId = $request->get('targetId');

        /**
         * @var \Bellwether\BWCMSBundle\Entity\ContentRepository $contentRepo
         * @var \Bellwether\BWCMSBundle\Entity\ContentEntity $content
         * @var \Bellwether\BWCMSBundle\Entity\ContentEntity $parentContent
         */
        $contentRepo = $this->cm()->getContentRepository();
        $content = $contentRepo->find($contentId);
        if (empty($content)) {
            return $this->returnErrorResponse('Invalid Data');
        }
        if ($targetId == 'Root') {
            $content->setTreeParent(null);
        } else {
            $parentContent = $contentRepo->find($targetId);
            if (empty($parentContent)) {
                return $this->returnErrorResponse('Invalid Data');
            }
            $content->setTreeParent($parentContent);
        }
        $this->em()->persist($content);
        $this->em()->flush();
        return $this->returnJsonReponse($request, array());
    }

    /**
     * @Route("/paste.php",name="_bwcms_admin_content_paste")
     * @Method({"POST"})
     */
    public function pasteAction(Request $request)
    {
        $command = $request->get('command');
        $contentIds = $request->get('contentIds');
        $targetId = $request->get('targetId');
        //$sourceFolderId = $request->get('sourceFolderId');
        $type = $request->get('type', 'Content');

        if (empty($command)) {
            return $this->returnErrorResponse('Invalid Data');
        }
        if (empty($contentIds)) {
            return $this->returnErrorResponse('Invalid Data');
        }
        if (empty($targetId)) {
            return $this->returnErrorResponse('Invalid Data');
        }
        $contentRepo = $this->cm()->getContentRepository();
        if ($targetId == 'Root') {
            $parentContent = null;
        } else {
            $parentContent = $contentRepo->find($targetId);
            if (empty($parentContent)) {
                return $this->returnErrorResponse('Invalid Data');
            }
        }
        $contentIds = explode(',', $contentIds);
        if ($command == 'cut') {
            foreach ($contentIds as $contentId) {
                if (!empty($contentId)) {
                    $content = $contentRepo->find($contentId);
                    if (empty($content)) {
                        return $this->returnErrorResponse('Invalid Data');
                    }
                    $content->setTreeParent($parentContent);
                    $this->em()->persist($content);
                }
            }
            $this->em()->flush();
        }
        if ($command == 'copy') {
            foreach ($contentIds as $contentId) {
                if (!empty($contentId)) {
                    $content = $contentRepo->find($contentId);
                    if (empty($content)) {
                        return $this->returnErrorResponse('Invalid Data');
                    }
                    $this->cm()->cloneContent($content, $parentContent);
                }
            }
        }

        $jsNodes = $this->getFolderTree($type, $targetId);
        return $this->returnJsonReponse($request, array('nodes' => $jsNodes));
    }

    /**
     * @Route("/delete.php",name="_bwcms_admin_content_delete")
     * @Method({"GET", "POST"})
     */
    public function deleteAction(Request $request)
    {
        $contentIds = $request->get('contentIds');
        $targetId = $request->get('targetId');
        $type = $request->get('type', 'Content');
        if (empty($contentIds)) {
            return $this->returnErrorResponse('Invalid Data');
        }
        if (empty($targetId)) {
            return $this->returnErrorResponse('Invalid Data');
        }
        /**
         * @var \Bellwether\BWCMSBundle\Entity\ContentEntity $content
         */
        $contentRepo = $this->cm()->getContentRepository();
        $contentIds = explode(',', $contentIds);
        $loadedContent = array();
        foreach ($contentIds as $contentId) {
            if (!empty($contentId)) {
                $content = $contentRepo->find($contentId);
                if (empty($content)) {
                    return $this->returnErrorResponse('Invalid Data');
                }
                if ($contentRepo->childCount($content) > 0) {
                    return $this->returnErrorResponse('Cannot delete when there are sub items inside the folder');
                }
                $loadedContent[] = $content;
            }
        }
        foreach ($loadedContent as $content) {
            $this->cm()->delete($content);
        }
        $jsNodes = $this->getFolderTree($type, $targetId);
        return $this->returnJsonReponse($request, array('nodes' => $jsNodes));
    }


    /**
     * @Route("/sort.php",name="_bwcms_admin_content_sort")
     * @Method({"POST"})
     */
    public function sortAction(Request $request)
    {
        $start = intval($request->get('start', 0));
        $end = intval($request->get('end', 0));
        $contentId = $request->get('contentId');

        $contentRepo = $this->cm()->getContentRepository();
        $content = $contentRepo->find($contentId);
        $toMove = $start - $end;

        try {
            if ($toMove > 0) {
                $contentRepo->moveUp($content, $toMove);
            }
            if ($toMove < 0) {
                $contentRepo->moveDown($content, $toMove * -1);
            }
        } catch (\Gedmo\Exception\InvalidArgumentException $exp) {
            return $this->returnErrorResponse($exp->getMessage());
        }
        return $this->returnJsonReponse($request, array());
    }


    /**
     * @Route("/download.php",name="_bwcms_admin_content_media_download")
     * @Method({"GET"})
     */
    public function downloadAction(Request $request)
    {
        $contentId = $request->get('contentId');
        /**
         * @var \Bellwether\BWCMSBundle\Entity\ContentRepository $contentRepository
         * @var \Bellwether\BWCMSBundle\Entity\ContentEntity $content
         */
        $contentRepository = $this->em()->getRepository('BWCMSBundle:ContentEntity');
        $content = $contentRepository->find($contentId);
        if ($content == null) {
            return new Response('Content not available', 500);
        }
        $media = $content->getMedia()->first();
        $downloadFile = $this->mm()->checkAndCreateMediaCacheFile($media);

        $response = new BinaryFileResponse($downloadFile);
        $response->trustXSendfileTypeHeader();
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            $media->getFile() . '.' . $media->getExtension(),
            iconv('UTF-8', 'ASCII//TRANSLIT', $media->getFile() . '.' . $media->getExtension())
        );

        return $response;
    }

}
