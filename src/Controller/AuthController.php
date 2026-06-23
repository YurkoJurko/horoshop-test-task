<?php

namespace App\Controller;

use App\Entity\ApiToken;
use App\Entity\User;
use App\Repository\ApiTokenRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

class AuthController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ApiTokenRepository $apiTokenRepository,
        private readonly UserRepository $userRepository,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
    }

    #[Route('/v1/api/login', name: 'api_login', methods: ['POST'])]
    public function login(Request $request): JsonResponse
    {
        $payload = $this->readJsonPayload($request);

        if (!is_array($payload)) {
            return $this->error('Invalid JSON body.', Response::HTTP_BAD_REQUEST);
        }

        foreach (['login', 'pass'] as $field) {
            if (!isset($payload[$field]) || !is_string($payload[$field]) || trim($payload[$field]) === '') {
                return $this->error(sprintf('Field "%s" is required.', $field), Response::HTTP_BAD_REQUEST);
            }
        }

        if (mb_strlen(trim($payload['login'])) > 8) {
            return $this->error('Field "login" must not exceed 8 characters.', Response::HTTP_BAD_REQUEST);
        }

        if (mb_strlen(trim($payload['pass'])) > 8) {
            return $this->error('Field "pass" must not exceed 8 characters.', Response::HTTP_BAD_REQUEST);
        }

        $user = $this->userRepository->findOneBy(['login' => trim($payload['login'])]);

        if (!$user instanceof User || !$this->passwordHasher->isPasswordValid($user, trim($payload['pass']))) {
            return $this->error('Invalid credentials.', Response::HTTP_UNAUTHORIZED);
        }

        if ($this->apiTokenRepository->countActiveByUser($user) >= 3) {
            $oldestToken = $this->apiTokenRepository->findOldestActiveByUser($user);

            if ($oldestToken !== null) {
                $oldestToken->setRevokedAt(new \DateTimeImmutable());
            }
        }

        $plainToken = bin2hex(random_bytes(32));
        $expiresAt = new \DateTimeImmutable('+1 day');

        $apiToken = (new ApiToken())
            ->setUser($user)
            ->setTokenHash(hash('sha256', $plainToken))
            ->setExpiresAt($expiresAt);

        $this->entityManager->persist($apiToken);
        $this->entityManager->flush();

        return $this->json([
            'token' => $plainToken,
            'token_type' => 'Bearer',
            'expires_at' => $expiresAt->format(DATE_ATOM),
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readJsonPayload(Request $request): ?array
    {
        $content = trim($request->getContent());

        if ($content === '') {
            return null;
        }

        $payload = json_decode($content, true);

        return is_array($payload) ? $payload : null;
    }

    private function error(string $message, int $statusCode): JsonResponse
    {
        return $this->json(['error' => $message], $statusCode);
    }
}
