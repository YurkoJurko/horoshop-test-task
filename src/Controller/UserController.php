<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class UserController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserRepository $userRepository,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly ValidatorInterface $validator,
    ) {
    }

    #[Route('/v1/api/users', name: 'api_users_get', methods: ['GET'])]
    public function show(Request $request, #[CurrentUser] User $currentUser): JsonResponse
    {
        $id = $this->readIdFromQuery($request);

        if ($id === null) {
            return $this->error('Query parameter "id" is required.', Response::HTTP_BAD_REQUEST);
        }

        $user = $this->userRepository->find($id);

        if (!$user instanceof User) {
            return $this->error('User not found.', Response::HTTP_NOT_FOUND);
        }

        if (!$this->canAccessUser($currentUser, $user)) {
            return $this->error('Access denied.', Response::HTTP_FORBIDDEN);
        }

        return $this->json([
            'login' => $user->getLogin(),
            'phone' => $user->getPhone(),
        ]);
    }

    #[Route('/v1/api/users', name: 'api_users_post', methods: ['POST'])]
    public function createUser(Request $request, #[CurrentUser] User $currentUser): JsonResponse
    {
        if (!$this->isRoot($currentUser)) {
            return $this->error('Access denied.', Response::HTTP_FORBIDDEN);
        }

        $payload = $this->readJsonPayload($request);

        if (!is_array($payload)) {
            return $this->error('Invalid JSON body.', Response::HTTP_BAD_REQUEST);
        }

        $validationError = $this->validateUserPayload($payload, requireId: false);

        if ($validationError !== null) {
            return $this->error($validationError, Response::HTTP_BAD_REQUEST);
        }

        $user = (new User())
            ->setLogin(trim($payload['login']))
            ->setPhone(trim($payload['phone']))
            ->setRoles(['ROLE_USER'])
            ->setPasswordHash('');

        $user->setPasswordHash($this->passwordHasher->hashPassword($user, trim($payload['pass'])));

        $entityError = $this->validateEntity($user);

        if ($entityError !== null) {
            return $this->error($entityError, Response::HTTP_BAD_REQUEST);
        }

        try {
            $this->entityManager->persist($user);
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            return $this->error('Login or phone already exists.', Response::HTTP_CONFLICT);
        }

        return $this->json([
            'id' => $user->getId(),
            'login' => $user->getLogin(),
            'phone' => $user->getPhone(),
        ], Response::HTTP_CREATED);
    }

    #[Route('/v1/api/users', name: 'api_users_put', methods: ['PUT'])]
    public function updateUser(Request $request, #[CurrentUser] User $currentUser): JsonResponse
    {
        $payload = $this->readJsonPayload($request);

        if (!is_array($payload)) {
            return $this->error('Invalid JSON body.', Response::HTTP_BAD_REQUEST);
        }

        $validationError = $this->validateUserPayload($payload, requireId: true);

        if ($validationError !== null) {
            return $this->error($validationError, Response::HTTP_BAD_REQUEST);
        }

        $user = $this->userRepository->find((int) $payload['id']);

        if (!$user instanceof User) {
            return $this->error('User not found.', Response::HTTP_NOT_FOUND);
        }

        if (!$this->canAccessUser($currentUser, $user)) {
            return $this->error('Access denied.', Response::HTTP_FORBIDDEN);
        }

        $user
            ->setLogin(trim($payload['login']))
            ->setPhone(trim($payload['phone']))
            ->setPasswordHash($this->passwordHasher->hashPassword($user, trim($payload['pass'])));

        $entityError = $this->validateEntity($user);

        if ($entityError !== null) {
            return $this->error($entityError, Response::HTTP_BAD_REQUEST);
        }

        try {
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            return $this->error('Login or phone already exists.', Response::HTTP_BAD_REQUEST);
        }

        return $this->json(['id' => $user->getId()]);
    }

    #[Route('/v1/api/users', name: 'api_users_delete', methods: ['DELETE'])]
    public function deleteUser(Request $request, #[CurrentUser] User $currentUser): JsonResponse
    {
        if (!$this->isRoot($currentUser)) {
            return $this->error('Access denied.', Response::HTTP_FORBIDDEN);
        }

        $id = $this->readIdFromQuery($request);

        if ($id === null) {
            $payload = $this->readJsonPayload($request, allowEmpty: true);
            $id = is_array($payload) ? $this->readIdFromPayload($payload) : null;
        }

        if ($id === null) {
            return $this->error('Field "id" is required.', Response::HTTP_BAD_REQUEST);
        }

        $user = $this->userRepository->find($id);

        if (!$user instanceof User) {
            return $this->error('User not found.', Response::HTTP_NOT_FOUND);
        }

        $this->entityManager->remove($user);
        $this->entityManager->flush();

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readJsonPayload(Request $request, bool $allowEmpty = false): ?array
    {
        $content = trim($request->getContent());

        if ($content === '') {
            return $allowEmpty ? [] : null;
        }

        $payload = json_decode($content, true);

        return is_array($payload) ? $payload : null;
    }

    private function readIdFromQuery(Request $request): ?int
    {
        $id = $request->query->get('id');

        if (!is_scalar($id) || !ctype_digit((string) $id)) {
            return null;
        }

        return (int) $id;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function readIdFromPayload(array $payload): ?int
    {
        $id = $payload['id'] ?? null;

        if (!is_int($id) && !(is_string($id) && ctype_digit($id))) {
            return null;
        }

        return (int) $id;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function validateUserPayload(array $payload, bool $requireId): ?string
    {
        if ($requireId && $this->readIdFromPayload($payload) === null) {
            return 'Field "id" is required.';
        }

        foreach (['login', 'phone', 'pass'] as $field) {
            if (!isset($payload[$field]) || !is_string($payload[$field]) || trim($payload[$field]) === '') {
                return sprintf('Field "%s" is required.', $field);
            }
        }

        if (mb_strlen(trim($payload['login'])) > 8) {
            return 'Field "login" must not exceed 8 characters.';
        }

        if (!preg_match('/^\d{10}$/', trim($payload['phone']))) {
            return 'Field "phone" must contain exactly 10 digits.';
        }

        if (mb_strlen(trim($payload['pass'])) > 8) {
            return 'Field "pass" must not exceed 8 characters.';
        }

        return null;
    }

    private function validateEntity(User $user): ?string
    {
        $violations = $this->validator->validate($user);

        if (count($violations) === 0) {
            return null;
        }

        return $violations[0]->getMessage();
    }

    private function canAccessUser(User $currentUser, User $targetUser): bool
    {
        return $this->isRoot($currentUser) || $currentUser->getId() === $targetUser->getId();
    }

    private function isRoot(User $user): bool
    {
        return in_array('ROLE_ROOT', $user->getRoles(), true);
    }

    private function error(string $message, int $statusCode): JsonResponse
    {
        return $this->json(['error' => $message], $statusCode);
    }
}
