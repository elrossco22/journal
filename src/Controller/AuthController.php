<?php

namespace eLife\Journal\Controller;

use eLife\ApiSdk\Collection\Sequence;
use eLife\Journal\Form\Type\LoginType;
use eLife\Journal\Helper\Paginator;
use eLife\Journal\Pagerfanta\SequenceAdapter;
use eLife\Patterns\ViewModel\ContentHeader;
use eLife\Patterns\ViewModel\InfoBar;
use eLife\Patterns\ViewModel\ListingTeasers;
use eLife\Patterns\ViewModel\Teaser;
use Firebase\JWT\JWT;
use GuzzleHttp\Client;
use function GuzzleHttp\Promise\promise_for;
use GuzzleHttp\Psr7\Request as GuzzlehttpRequest;
use Michelf\MarkdownExtra;
use Pagerfanta\Pagerfanta;
use Symfony\Component\Form\Form;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class AuthController extends Controller
{
    public function redirectAction() : Response
    {
        if (!$this->get('security.authorization_checker')->isGranted('FEATURE_CAN_AUTHENTICATE')) {
            throw new NotFoundHttpException('Not found');
        }

        $request = $this->get('request_stack')->getCurrentRequest();
        $path['_forwarded'] = $request->attributes;
        $path['_controller'] = 'HWIOAuthBundle:Connect:redirectToService';
        $path['service'] = 'elife';
        $subRequest = $request->duplicate([], null, $path);

        return $this->get('http_kernel')->handle($subRequest, HttpKernelInterface::SUB_REQUEST);

    }

    public function authAction(Request $request) : JsonResponse
    {
        $data = [];
        if ($user = $this->get('session')->get('user')) {
            $authority = $this->getParameter('hypothes_is_authority');
            $client_id = $this->getParameter('hypothes_is_client_id_jwt');
            $client_secret = $this->getParameter('hypothes_is_client_secret_jwt');

            $now = time();
            $userid = "acct:{$user}@" . $authority;

            $payload = [
                'aud' => 'hypothes.is',
                'iss' => $client_id,
                'sub' => $userid,
                'nbf' => $now,
                'exp' => $now + 600,
            ];

            $data['jwt'] = JWT::encode($payload, $client_secret, 'HS256');
            $data['exp'] = date('Y-m-dTH:i:s', $payload['exp']);
        }

        $response = new JsonResponse($data);
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-cache');
        $response->headers->addCacheControlDirective('no-store');
        $response->headers->addCacheControlDirective('must-revalidate');
        return $response;
    }

    public function registerAction(Request $request) : Response
    {
        $arguments = $this->defaultPageArguments($request);

        $arguments['title'] = 'Register';

        $arguments['contentHeader'] = new ContentHeader($arguments['title']);
        $arguments['body'] = '';

        $response = new Response($this->get('templating')->render('::alerts.html.twig', $arguments));
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-cache');
        $response->headers->addCacheControlDirective('no-store');
        $response->headers->addCacheControlDirective('must-revalidate');

        return $response;
    }

    public function loginAction(Request $request) : Response
    {
        if ($destination = $request->query->get('destination')) {
            $this->get('session')->set('destination', $destination);
        }

        $arguments = $this->defaultPageArguments($request);

        $arguments['title'] = 'Login';

        $arguments['contentHeader'] = new ContentHeader($arguments['title']);

        /** @var Form $form */
        $form = $this->get('form.factory')
            ->create(LoginType::class, null, ['action' => $this->get('router')->generate('login')]);

        $form->handleRequest($request);

        if ($form->isSubmitted()) {
            if ($form->isValid()) {
                $this->get('session')->set('user', $form->get('username')->getData());
                $this->get('session')
                    ->getFlashBag()
                    ->add(InfoBar::TYPE_SUCCESS,
                        'Welcome back '.$form->get('username')->getData());

                $destination = $this->get('session')->get('destination', $this->get('router')->generate('home'));
                $this->get('session')->remove('destination');

                return new RedirectResponse($destination);
            }

            foreach ($form->getErrors(true) as $error) {
                $this->get('session')
                    ->getFlashBag()
                    ->add(InfoBar::TYPE_ATTENTION, $error->getMessage());
            }
        }
        else {
            $this->get('session')->remove('user');
        }

        $arguments['form'] = $this->get('elife.journal.view_model.converter')->convert($form->createView());

        $response = new Response($this->get('templating')->render('::login.html.twig', $arguments));
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-cache');
        $response->headers->addCacheControlDirective('no-store');
        $response->headers->addCacheControlDirective('must-revalidate');

        return $response;
    }

    public function logoutAction(Request $request) : Response
    {
        if ($user = $this->get('session')->get('user')) {
            $this->get('session')
                ->getFlashBag()
                ->add(InfoBar::TYPE_SUCCESS,
                    'Goodbye '.$user);
            $this->get('session')->remove('user');
        }

        $destination = $request->query->get('destination', $this->get('router')->generate('login'));
        return new RedirectResponse($destination);
    }

    public function profileAction(Request $request, string $uid) : Response
    {
        $arguments = $this->defaultPageArguments($request);

        $uid = (!empty($uid)) ? $uid : $this->get('session')->get('user');

        if (!$uid) {
            return new RedirectResponse($this->get('router')->generate('login'));
        }

        $client = new Client();
        $timeout = 10;

        $gather_rows = function($offset = 0) use ($uid, $client, $timeout, $request) {
            $hyp_request = new GuzzlehttpRequest('GET', $this->getParameter('hypothes_is_api_url').'search?offset='.$offset.'&user='.$uid.'&group='.$request->query->get('group', $this->getParameter('hypothes_is_group')));
            $response = $client->send($hyp_request, ['timeout' => $timeout]);
            $data = json_decode($response->getBody()->getContents());
            if (!empty($data->rows)) {
                return $data->rows;
            }
            else {
                return [];
            }
        };

        $rows = [];
        while ($next = $gather_rows(count($rows))) {
            $rows = array_merge($rows, $next);
        }

        $recents = [];
        $months = [];
        $recent = date('Ymd', strtotime('-7 days'));

        foreach ($rows as $k => $row) {
            if (!empty($row->references) || ($uid != $this->get('session')->get('user') && $row->hidden)) {
                unset($rows[$k]);
                continue;
            }
            $row->timestamp = strtotime($row->updated ?? $row->created);
            $month = date('Y-m', $row->timestamp);
            $day = date('Ymd', $row->timestamp);
            $source = $row->target[0]->source;
            $source = preg_replace('~^https?://[^/]+(/[^/]+/[^/]+)~', '$1', $source);
            $months[$month][$source]['items'][] = $row;
            $meta = [
               'title' => $row->document->title[0] ?? $source,
            ];
            $months[$month][$source] += $meta;
            if ($day > $recent) {
                $recents[$source]['items'][] = $row;
                $recents[$source] += $meta;
            }
        }

        $prepare_item = function ($items, $url, $title) {
            if (!empty($items)) {
                $annotations = '';
                $title_url = null;
                foreach ($items as $item) {
                    $title_url = $item->links->incontext;
                    $annotations .= sprintf('<p><a href="%s">%s</a></p>', $item->links->incontext, date('M j, Y', $item->timestamp));
                    if ($item->hidden) {
                        $annotations .= '<p><em>private</em></p>';
                    }
                    if (!empty($item->target[0]->selector)) {
                        $selectors = [];
                        foreach ($item->target[0]->selector as $selector) {
                            $selectors += (array) $selector;
                        }
                        if (!empty($selectors['exact'])) {
                            $annotations .= sprintf('<p><strong>Highlighted text:</strong> %s</p>', htmlentities($selectors['exact']));
                        }
                    }
                    if (!empty($item->text)) {
                        $annotations .= sprintf('<p><strong>Comment:</strong> %s</p>', MarkdownExtra::defaultTransform($item->text));
                    }
                }
                return sprintf('<h5><a href="%s">%s</a></h5>', $title_url ?? $url, $title) . $annotations;
            }
            else {
                return '';
            }
        };

        $arguments['title'] = sprintf('User: %s (Annotations: %d)', $uid, count($rows));

        $arguments['contentHeader'] = new ContentHeader($arguments['title']);
        $profile = '';
        if (!empty($recents)) {
            $profile .= '<h3>Last 7 days</h3>';
            foreach ($recents as $url => $source) {
                $profile .= $prepare_item($source['items'], $url, $source['title']);
            }
        }
        if (!empty($months)) {
            $profile .= '<h3>Months</h3>';
            $limit = 7;
            foreach ($months as $month => $sources) {
                if ($limit >= 0) {
                    $limit--;
                }
                if ($limit >= 0) {
                    $profile .= '<h4>'.date('F Y', strtotime($month.'-01')).'</h4>';
                    foreach ($sources as $url => $source) {
                        $profile .= $prepare_item($source['items'], $url, $source['title']);
                    }
                }
            }
        }
        $arguments['profile'] = $profile;

        $response = new Response($this->get('templating')->render('::dummy-profile.html.twig', $arguments));
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-cache');
        $response->headers->addCacheControlDirective('no-store');
        $response->headers->addCacheControlDirective('must-revalidate');

        return $response;
    }

    public function listAction(Request $request) : Response
    {
        $page = (int) $request->query->get('page', 1);
        $perPage = 10;

        $arguments = $this->defaultPageArguments($request);

        $latest = promise_for($this->get('elife.api_sdk.blog_articles'))
            ->then(function (Sequence $sequence) use ($page, $perPage) {
                $pagerfanta = new Pagerfanta(new SequenceAdapter($sequence, $this->willConvertTo(Teaser::class)));
                $pagerfanta->setMaxPerPage($perPage)->setCurrentPage($page);

                return $pagerfanta;
            });

        $arguments['title'] = 'Inside eLife';

        $arguments['paginator'] = $latest
            ->then(function (Pagerfanta $pagerfanta) use ($request) {
                return new Paginator(
                    'Browse Inside eLife',
                    $pagerfanta,
                    function (int $page = null) use ($request) {
                        $routeParams = $request->attributes->get('_route_params');
                        $routeParams['page'] = $page;

                        return $this->get('router')->generate('wtf', $routeParams);
                    }
                );
            });

        $arguments['listing'] = $arguments['paginator']
            ->then($this->willConvertTo(ListingTeasers::class, ['heading' => 'Latest', 'emptyText' => 'No articles available.']));
        dump($arguments);

        if (1 === $page) {
            return $this->createFirstPage($arguments);
        }

        return $this->createSubsequentPage($request, $arguments);
    }

    private function createFirstPage(array $arguments) : Response {
      $arguments['contentHeader'] = new ContentHeader($arguments['title']);

      return new Response($this->get('templating')
        ->render('::inside-elife.html.twig', $arguments));
    }
}
