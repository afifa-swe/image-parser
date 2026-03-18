<?php

namespace App\Controller;

use App\Service\ImageParserService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class ImageParserController extends AbstractController
{
    public function __construct(
        private readonly ImageParserService $imageParserService,
    ) {}

    #[Route('/', name: 'app_index', methods: ['GET'])]
    public function index(): Response
    {
        $images = $this->imageParserService->getSavedImages();

        return $this->render('image_parser/index.html.twig', [
            'images' => $images,
        ]);
    }

    #[Route('/parse', name: 'app_parse', methods: ['POST'])]
    public function parse(Request $request): JsonResponse
    {
        $url = trim((string) $request->request->get('url', ''));
        $minWidth = (int) $request->request->get('min_width', 100);
        $minHeight = (int) $request->request->get('min_height', 100);
        $overlayText = trim((string) $request->request->get('overlay_text', ''));

        if (empty($url)) {
            return $this->json(['error' => 'URL не может быть пустым'], 400);
        }

        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return $this->json(['error' => 'Некорректный URL'], 400);
        }

        if ($minWidth < 1 || $minHeight < 1) {
            return $this->json(['error' => 'Минимальные размеры должны быть больше 0'], 400);
        }

        try {
            $savedImages = $this->imageParserService->parseAndSaveImages($url, $minWidth, $minHeight, $overlayText);

            return $this->json([
                'success' => true,
                'count' => count($savedImages),
                'images' => $savedImages,
            ]);
        } catch (\Throwable $e) {
            return $this->json(['error' => 'Ошибка при обработке: ' . $e->getMessage()], 500);
        }
    }

    #[Route('/images/{filename}', name: 'app_image', methods: ['GET'])]
    public function serveImage(string $filename): Response
    {
        $shareDir = $this->getParameter('kernel.project_dir') . '/' . ($_ENV['APP_SHARE_DIR'] ?? 'var/share');
        $filepath = $shareDir . '/' . $filename;

        if (!file_exists($filepath)) {
            throw $this->createNotFoundException('Изображение не найдено');
        }

        return new Response(
            file_get_contents($filepath),
            200,
            [
                'Content-Type' => 'image/png',
                'Cache-Control' => 'public, max-age=3600',
            ]
        );
    }
}