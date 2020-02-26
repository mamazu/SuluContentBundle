<?php

declare(strict_types=1);

/*
 * This file is part of Sulu.
 *
 * (c) Sulu GmbH
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Sulu\Bundle\ContentBundle\Tests\Application\ExampleTestBundle\Controller;

use Doctrine\ORM\EntityManagerInterface;
use FOS\RestBundle\Controller\Annotations as Rest;
use FOS\RestBundle\Routing\ClassResourceInterface;
use FOS\RestBundle\View\ViewHandlerInterface;
use Sulu\Bundle\ContentBundle\Content\Application\ContentManager\ContentManagerInterface;
use Sulu\Bundle\ContentBundle\Content\Domain\Model\ContentProjectionInterface;
use Sulu\Bundle\ContentBundle\Content\Domain\Model\WorkflowInterface;
use Sulu\Bundle\ContentBundle\Tests\Application\ExampleTestBundle\Entity\Example;
use Sulu\Bundle\ContentBundle\Tests\Application\ExampleTestBundle\Entity\ExampleContentProjection;
use Sulu\Component\Rest\AbstractRestController;
use Sulu\Component\Rest\Exception\RestException;
use Sulu\Component\Rest\ListBuilder\Doctrine\DoctrineListBuilder;
use Sulu\Component\Rest\ListBuilder\Doctrine\DoctrineListBuilderFactoryInterface;
use Sulu\Component\Rest\ListBuilder\Doctrine\FieldDescriptor\DoctrineFieldDescriptorInterface;
use Sulu\Component\Rest\ListBuilder\Metadata\FieldDescriptorFactoryInterface;
use Sulu\Component\Rest\ListBuilder\PaginatedRepresentation;
use Sulu\Component\Rest\RestHelperInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

class ExampleController extends AbstractRestController implements ClassResourceInterface
{
    /**
     * @var FieldDescriptorFactoryInterface
     */
    private $fieldDescriptorFactory;

    /**
     * @var DoctrineListBuilderFactoryInterface
     */
    private $listBuilderFactory;

    /**
     * @var RestHelperInterface
     */
    private $restHelper;

    /**
     * @var ContentManagerInterface
     */
    private $contentManager;

    /**
     * @var EntityManagerInterface
     */
    private $entityManager;

    public function __construct(
        ViewHandlerInterface $viewHandler,
        TokenStorageInterface $tokenStorage,
        FieldDescriptorFactoryInterface $fieldDescriptorFactory,
        DoctrineListBuilderFactoryInterface $listBuilderFactory,
        RestHelperInterface $restHelper,
        ContentManagerInterface $contentManager,
        EntityManagerInterface $entityManager
    ) {
        $this->fieldDescriptorFactory = $fieldDescriptorFactory;
        $this->listBuilderFactory = $listBuilderFactory;
        $this->restHelper = $restHelper;
        $this->contentManager = $contentManager;
        $this->entityManager = $entityManager;

        parent::__construct($viewHandler, $tokenStorage);
    }

    /**
     * The cgetAction looks like for a normal list action.
     */
    public function cgetAction(Request $request): Response
    {
        /** @var DoctrineFieldDescriptorInterface[] $fieldDescriptors */
        $fieldDescriptors = $this->fieldDescriptorFactory->getFieldDescriptors(Example::RESOURCE_KEY);
        /** @var DoctrineListBuilder $listBuilder */
        $listBuilder = $this->listBuilderFactory->create(Example::class);
        $listBuilder->setParameter('locale', $request->query->get('locale'));
        $this->restHelper->initializeListBuilder($listBuilder, $fieldDescriptors);

        $listRepresentation = new PaginatedRepresentation(
            $listBuilder->execute(),
            Example::RESOURCE_KEY,
            (int) $listBuilder->getCurrentPage(),
            (int) $listBuilder->getLimit(),
            $listBuilder->count()
        );

        return $this->handleView($this->view($listRepresentation));
    }

    /**
     * The getAction loads the main entity and resolves its content based on the current dimensionAttributes.
     */
    public function getAction(Request $request, int $id): Response
    {
        /** @var Example|null $example */
        $example = $this->entityManager->getRepository(Example::class)->findOneBy(['id' => $id]);

        if (!$example) {
            throw new NotFoundHttpException();
        }

        $dimensionAttributes = $this->getDimensionAttributes($request);
        $contentProjection = $this->contentManager->resolve($example, $dimensionAttributes);

        return $this->handleView($this->view($this->resolve($example, $contentProjection)));
    }

    /**
     * The postAction creates the main entity and saves its content based on the current dimensionAttributes.
     */
    public function postAction(Request $request): Response
    {
        $example = new Example();

        $data = $this->getData($request);
        $dimensionAttributes = $this->getDimensionAttributes($request); // ["locale" => "en", "stage" => "draft"]

        $contentProjection = $this->contentManager->persist($example, $data, $dimensionAttributes);

        $this->entityManager->persist($example);
        $this->entityManager->flush();

        if ('publish' === $request->query->get('action')) {
            $contentProjection = $this->contentManager->applyTransition(
                $example,
                $dimensionAttributes,
                WorkflowInterface::WORKFLOW_TRANSITION_PUBLISH
            );

            $this->entityManager->flush();
        }

        return $this->handleView($this->view($this->resolve($example, $contentProjection), 201));
    }

    /**
     *  The postTriggerAction loads the main entity and applies transitions based on the given action parameter.
     *
     * @Rest\Post("examples/{id}")
     */
    public function postTriggerAction(string $id, Request $request): Response
    {
        /** @var Example|null $example */
        $example = $this->entityManager->getRepository(Example::class)->findOneBy(['id' => $id]);

        if (!$example) {
            throw new NotFoundHttpException();
        }

        $dimensionAttributes = $this->getDimensionAttributes($request); // ["locale" => "en", "stage" => "draft"]

        $action = $request->query->get('action');

        switch ($action) {
            case 'unpublish':
                $contentProjection = $this->contentManager->applyTransition(
                    $example,
                    $dimensionAttributes,
                    WorkflowInterface::WORKFLOW_TRANSITION_UNPUBLISH
                );
                $this->entityManager->flush();

                return $this->handleView($this->view($this->resolve($example, $contentProjection)));
            case 'remove-draft':
                $contentProjection = $this->contentManager->applyTransition(
                    $example,
                    $dimensionAttributes,
                    WorkflowInterface::WORKFLOW_TRANSITION_REMOVE_DRAFT
                );
                $this->entityManager->flush();

                return $this->handleView($this->view($this->resolve($example, $contentProjection)));
            default:
                throw new RestException('Unrecognized action: ' . $action);
        }
    }

    /**
     * The putAction loads the main entity and saves its content based on the current dimensionAttributes.
     */
    public function putAction(Request $request, int $id): Response
    {
        /** @var Example|null $example */
        $example = $this->entityManager->getRepository(Example::class)->findOneBy(['id' => $id]);

        if (!$example) {
            throw new NotFoundHttpException();
        }

        $data = $this->getData($request);
        $dimensionAttributes = $this->getDimensionAttributes($request); // ["locale" => "en", "stage" => "draft"]

        /** @var ExampleContentProjection $contentProjection */
        $contentProjection = $this->contentManager->persist($example, $data, $dimensionAttributes);
        if (WorkflowInterface::WORKFLOW_PLACE_PUBLISHED === $contentProjection->getWorkflowPlace()) {
            $contentProjection = $this->contentManager->applyTransition(
                $example,
                $dimensionAttributes,
                WorkflowInterface::WORKFLOW_TRANSITION_CREATE_DRAFT
            );
        }

        $this->entityManager->flush();

        if ('publish' === $request->query->get('action')) {
            $contentProjection = $this->contentManager->applyTransition(
                $example,
                $dimensionAttributes,
                WorkflowInterface::WORKFLOW_TRANSITION_PUBLISH
            );

            $this->entityManager->flush();
        }

        return $this->handleView($this->view($this->resolve($example, $contentProjection)));
    }

    /**
     * The deleteAction handles the main entity through cascading also the content will be removed.
     */
    public function deleteAction(int $id): Response
    {
        /** @var Example $example */
        $example = $this->entityManager->getReference(Example::class, $id);
        $this->entityManager->remove($example);
        $this->entityManager->flush();

        return new Response('', 204);
    }

    /**
     * Will return e.g. ['locale' => 'en'].
     *
     * @return array<string, mixed>
     */
    protected function getDimensionAttributes(Request $request): array
    {
        return $request->query->all();
    }

    /**
     * Will return e.g. ['title' => 'Test', 'template' => 'example-2', ...].
     *
     * @return array<string, mixed>
     */
    protected function getData(Request $request): array
    {
        $data = $request->request->all();

        return $data;
    }

    /**
     * Resolve will convert the ContentProjection object into a normalized array.
     *
     * @return mixed[]
     */
    protected function resolve(Example $example, ContentProjectionInterface $contentProjection): array
    {
        $resolvedData = $this->contentManager->normalize($contentProjection);

        // If used autoincrement ids the id need to be set here on
        // the resolvedData else on create no id will be returned
        $resolvedData['id'] = $example->getId();

        return $resolvedData;
    }
}
