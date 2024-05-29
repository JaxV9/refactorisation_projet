<?php

namespace App\Controller;

use App\Entity\Game;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Validator\Constraints as Assert;

class GameController extends AbstractController
{
    #[Route('/games', name: 'get_list_of_games', methods: ['GET'])]
    public function getGameList(EntityManagerInterface $entityManager): JsonResponse
    {
        $payload = $entityManager->getRepository(Game::class)->findAll();
        return $this->json(
            $payload,
            headers: ['Content-Type' => 'application/json;charset=UTF-8']
        );
    }

    #[Route('/games', name: 'create_game', methods: ['POST'])]
    public function launchGame(Request $request, EntityManagerInterface $entityManager): JsonResponse
    {
        $userId = $request->headers->get('X-User-Id');
        if (!isset($userId) || ctype_digit($userId) === false) {
            return new JsonResponse('User not found', 401);
        }
        $user = $entityManager->getRepository(User::class)->find($userId);
        if (!isset($user)) {
            return new JsonResponse('User not found', 401);
        }

        $game = new Game();
        $game->setState('pending');
        $game->setPlayerLeft($user);

        $entityManager->persist($game);

        $entityManager->flush();

        return $this->json(
            $game,
            201,
            headers: ['Content-Type' => 'application/json;charset=UTF-8']
        );

    }

    #[Route('/game/{id}', name: 'fetch_game', methods: ['GET'])]
    public function getGameInfo(EntityManagerInterface $entityManager, $id): JsonResponse
    {
        if (ctype_digit($id) === false) {
            return new JsonResponse('Game not found', 404);
        }
        $party = $entityManager->getRepository(Game::class)->findOneBy(['id' => $id]);
        if ($party === null) {
            return new JsonResponse('Game not found', 404);
        }
        return $this->json(
            $party,
            headers: ['Content-Type' => 'application/json;charset=UTF-8']
        );
    }

    #[Route('/game/{id}/add/{playerRightId}', name: 'add_user_right', methods: ['PATCH'])]
    public function inviteToGame(Request $request, EntityManagerInterface $entityManager, $id, $playerRightId): JsonResponse
    {
        $userId = $request->headers->get('X-User-Id');

        if (empty($userId) || ctype_digit($userId) === false) {
            return new JsonResponse('User not found', 401);
        }

        if ((ctype_digit($id) === false) && (ctype_digit($playerRightId) === false)) {
            return new JsonResponse('Game not found', 404);
        }

        $playerLeft = $entityManager->getRepository(User::class)->find($userId);

        if ($playerLeft === null) {
            return new JsonResponse('User not found', 401);
        }

        $game = $entityManager->getRepository(Game::class)->find($id);

        if ($game === null) {
            return new JsonResponse('Game not found', 404);
        }

        if ($game->getState() !== 'pending') {
            return new JsonResponse('Game already started', 409);
        }

        $playerRight = $entityManager->getRepository(User::class)->find($playerRightId);
        if ($playerRight === null) {
            return new JsonResponse('User not found', 404);
        }

        if ($playerLeft->getId() === $playerRight->getId()) {
            return new JsonResponse('You can\'t play against yourself', 409);
        }

        $game->setPlayerRight($playerRight);
        $game->setState('ongoing');

        $entityManager->flush();

        return $this->json(
            $game,
            headers: ['Content-Type' => 'application/json;charset=UTF-8']
        );
    }

    #[Route('/game/{id}', name: 'send_choice', methods: ['PATCH'])]
    public function play(Request $request, EntityManagerInterface $entityManager, $id): JsonResponse
    {
        $validChoices = ['rock', 'paper', 'scissors'];

        $userId = $request->headers->get('X-User-Id');

        if (ctype_digit($userId) === false) {
            return new JsonResponse('User not found', 401);
        }

        $user = $entityManager->getRepository(User::class)->find($userId);

        if ($user === null) {
            return new JsonResponse('User not found', 401);
        }

        if (ctype_digit($id) === false) {
            return new JsonResponse('Game not found', 404);
        }

        $game = $entityManager->getRepository(Game::class)->find($id);

        if ($game === null) {
            return new JsonResponse('Game not found', 404);
        }

        $userIsPlayerLeft = false;
        $userIsPlayerRight = $userIsPlayerLeft;

        if ($game->getPlayerLeft()->getId() === $user->getId()) {
            $userIsPlayerLeft = true;
        } elseif ($game->getPlayerRight()->getId() === $user->getId()) {
            $userIsPlayerRight = true;
        }

        if (false === $userIsPlayerLeft && !$userIsPlayerRight) {
            return new JsonResponse('You are not a player of this game', 403);
        }

        // we must check the game is ongoing and the user is a player of this game
        if ($game->getState() !== 'ongoing') {
            return new JsonResponse('Game not started', 409);
        }

        $form = $this->createFormBuilder()
            ->add('choice', TextType::class, [
                'constraints' => [
                    new Assert\NotBlank()
                ]
            ])
            ->getForm();

        $choice = json_decode($request->getContent(), true);

        $form->submit($choice);

        if ($form->isValid() === false) {
            return new JsonResponse('Invalid choice', 400);
        }

        $payload = $form->getData();
        if (!in_array($payload['choice'], $validChoices, true)) {
            return new JsonResponse('Invalid choice', 400);
        }

        if ($userIsPlayerLeft) {
            $game->setPlayLeft($payload['choice']);
            $entityManager->flush();

            if ($game->getPlayRight() !== null) {

                switch ($payload['choice']) {
                    case 'rock':
                        if ($game->getPlayRight() === 'paper') {
                            $game->setResult('winRight');
                            break;
                        }
                        if ($game->getPlayRight() === 'scissors') {
                            $game->setResult('winLeft');
                            break;
                        }
                        $game->setResult('draw');
                        break;
                    case 'paper':
                        if ($game->getPlayRight() === 'scissors') {
                            $game->setResult('winRight');
                            break;
                        }
                        if ($game->getPlayRight() === 'rock') {
                            $game->setResult('winLeft');
                            break;
                        }
                        $game->setResult('draw');
                        break;
                    case 'scissors':
                        if ($game->getPlayRight() === 'rock') {
                            $game->setResult('winRight');
                            break;
                        }
                        if ($game->getPlayRight() === 'paper') {
                            $game->setResult('winLeft');
                            break;
                        }
                        $game->setResult('draw');
                        break;
                }

                $game->setState('finished');
                $entityManager->flush();

                return $this->json(
                    $game,
                    headers: ['Content-Type' => 'application/json;charset=UTF-8']
                );
            }

            return $this->json(
                $game,
                headers: ['Content-Type' => 'application/json;charset=UTF-8']
            );

        }
        if ($userIsPlayerRight) {
            $game->setPlayRight($payload['choice']);

            $entityManager->flush();


            if ($game->getPlayLeft() !== null) {

                switch ($payload['choice']) {
                    case 'rock':
                        if ($game->getPlayLeft() === 'paper') {
                            $game->setResult('winLeft');
                            break;
                        }
                        if ($game->getPlayLeft() === 'scissors') {
                            $game->setResult('winRight');
                            break;
                        }
                        $game->setResult('draw');
                        break;
                    case 'paper':
                        if ($game->getPlayLeft() === 'scissors') {
                            $game->setResult('winLeft');
                            break;
                        }
                        if ($game->getPlayLeft() === 'rock') {
                            $game->setResult('winRight');
                            break;
                        }
                        $game->setResult('draw');
                        break;
                    case 'scissors':
                        if ($game->getPlayLeft() === 'rock') {
                            $game->setResult('winLeft');
                            break;
                        }
                        if ($game->getPlayLeft() === 'paper') {
                            $game->setResult('winRight');
                            break;
                        }
                        $game->setResult('draw');
                        break;
                }

                $game->setState('finished');
                $entityManager->flush();

                return $this->json(
                    $game,
                    headers: ['Content-Type' => 'application/json;charset=UTF-8']
                );

            }
            return $this->json(
                $game,
                headers: ['Content-Type' => 'application/json;charset=UTF-8']
            );

        }

        return new JsonResponse('coucou');
    }

    #[Route('/game/{id}', name: 'annuler_game', methods: ['DELETE'])]
    public function deleteGame(EntityManagerInterface $entityManager, Request $request, $id): JsonResponse
    {

        if (ctype_digit($id) === false) {
            return new JsonResponse('Game not found', 404);
        }

        $userId = $request->headers->get('X-User-Id');

        if (ctype_digit($userId) === false) {
            return new JsonResponse('User not found', 401);
        }

        $player = $entityManager->getRepository(User::class)->find($userId);

        if ($player === null) {
            return new JsonResponse('User not found', 401);
        }

        $game = $entityManager->getRepository(Game::class)->findOneBy(['id' => $id, 'playerLeft' => $player]);

        if (empty($game)) {
            $game = $entityManager->getRepository(Game::class)->findOneBy(['id' => $id, 'playerRight' => $player]);
        }

        if (empty($game)) {
            return new JsonResponse('Game not found', 403);
        }

        $entityManager->remove($game);
        $entityManager->flush();

        return new JsonResponse(null, 204);
    }
}