<?php

namespace App\Controller\Admin;

use App\Form\HtmlTextareaType;
use App\Service\SettingService;
use App\Service\SettingType;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Vich\UploaderBundle\Form\Type\VichFileType;

#[Route(path: '/setting', name: 'setting')]
#[IsGranted('ROLE_ADMIN_CONTENT')]
class SettingController extends AbstractController
{
    private readonly SettingService $service;

    public function __construct(SettingService $service)
    {
        $this->service = $service;
    }

    #[Route(path: '/', name: '', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        // Group keys by category (first segment before '.')
        $groups = [];
        foreach (SettingService::getKeys() as $key) {
            $parts = explode('.', (string) $key, 2);
            $groups[$parts[0]][] = $key;
        }

        if ($request->isMethod('POST')) {
            // Handle per-key delete buttons (pressing one submits the whole form,
            // so the deleted key must be excluded from the batch to avoid re-creation)
            $deletedKeys = [];
            // The delete button name "delete_KEY" (flat, no brackets) parses as a scalar
            // in $_POST, so we read it via has('delete_' . $key).
            foreach (SettingService::getKeys() as $key) {
                if ($request->request->has('delete_' . $key)) {
                    $this->service->deleteKey($key);
                    $deletedKeys[$key] = true;
                }
            }

            // Save all text values (file-type keys via the modal; skip deleted keys)
            $batch = [];
            foreach (SettingService::getKeys() as $key) {
                $type = SettingService::getType($key);
                if ($type === SettingType::File) {
                    continue;
                }
                if (isset($deletedKeys[$key])) {
                    continue;
                }
                if (!$request->request->has($key)) {
                    continue;
                }
                $batch[$key] = $this->normalizeSettingValue($type, $request->request->get($key));
            }
            $this->service->setSettingsBatch($batch);

            // Flush any pending file lifecycle callbacks (VichFileType)
            $this->service->flushAll();

            $this->addFlash('success', 'Einstellungen gespeichert.');
            return $this->redirectToRoute('admin_setting');
        }

        // Build field descriptors for rendering (dotted name attributes; Symfony 7.4
        // forbids dots in form field names, so the fields are rendered manually).
        $fields = [];
        foreach (SettingService::getKeys() as $key) {
            $type = SettingService::getType($key);
            $value = $this->service->get($key);
            $fields[$key] = [
                'type' => $type,
                'desc' => SettingService::getDescription($key),
                'isSet' => $this->service->isSet($key),
                'value' => $value,
                'displayValue' => $type === SettingType::Money ? (empty((string) $value) ? '0' : ((string) $value) / 100) : $value,
            ];
        }

        return $this->render('admin/settings/index.html.twig', [
            'groups' => $groups,
            'fields' => $fields,
            'service' => $this->service,
        ]);
    }

    /**
     * Convert a raw form value (view representation) to the stored text representation.
     * Money is submitted in euros and stored as integer cents; everything else as-is.
     */
    private function normalizeSettingValue(?SettingType $type, $raw): string
    {
        return match ($type) {
            SettingType::Money => (string) round(((float) $raw) * 100),
            SettingType::Integer => (string) intval($raw),
            default => (string) $raw,
        };
    }

    #[Route(path: '/edit', name: '_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request): Response
    {
        $key = $request->get('key', '');
        if (!$this->service->validKey($key)) {
            return $this->redirectToRoute('admin_setting');
        }

        $fb = $this->createFormBuilder($this->service->getSettingObject($key))
            ->setAction($this->generateUrl('admin_setting_edit', ['key' => $key]))
            ->add('key', HiddenType::class);

        $options = ['required' => false, 'label' => false];
        switch (SettingService::getType($key)) {
            default:
            case SettingType::String:
                $fb->add('text', TextType::class, $options);
                break;
            case SettingType::HTML:
                $fb->add('text', HtmlTextareaType::class, $options);
                break;
            case SettingType::URL:
                $fb->add('text', UrlType::class, $options);
                break;
            case SettingType::Integer:
                $fb->add('text', IntegerType::class, $options);
                $fb->get('text')
                    ->addModelTransformer(new CallbackTransformer(
                        fn($a) => intval($a),
                        fn($a) => strval($a)
                    ));
                break;
            case SettingType::Money:
                $fb->add('text', MoneyType::class, array_merge($options, ['divisor' => 100]));
                $fb->get('text')
                    ->addModelTransformer(new CallbackTransformer(
                        fn($a) => intval($a),
                        fn($a) => empty($a) ? "0" : strval($a)
                    ));
                break;
            case SettingType::File:
                $fb->add('file', VichFileType::class, [
                    'required' => false,
                    'label' => false,
                    'download_uri' => false,
                    'allow_delete' => true,
                    'delete_label' => 'Löschen',
                    ]);
                break;
            case SettingType::Bool:
                $fb->add('text', ChoiceType::class, [
                    'choices' => [
                        'Aktiviert' => '1',
                        'Deaktiviert' => '0',
                    ],
                    'expanded' => true,
                    'required' => true,
                    'label' => false,
                ]);
                break;
        }

        $fb->add('delete', SubmitType::class, ['label' => 'Löschen']);
        $fb->add('save', SubmitType::class, ['label' => 'Speichern']);

        $form = $fb->getForm();
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            if ($form->get('delete')->isClicked()) {
                $this->service->remove($key);
            } else if ($form->get('save')->isClicked()) {
                $data = $form->getData();
                $this->service->setSettingsObject($data);
            }

            return $this->redirectToRoute('admin_setting', ['_fragment' => $key]);
        }

        return $this->render(!$request->isXmlHttpRequest() ? 'admin/settings/edit.html.twig' : 'admin/settings/edit.modal.html.twig', [
            'key' => $key,
            'desc' => SettingService::getDescription($key),
            'form' => $form->createView(),
        ]);
    }
}
