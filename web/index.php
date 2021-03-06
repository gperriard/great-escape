<?php

// web/index.php
require_once __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/../src/Museomix/Entity/Artefact.php';

use Abraham\TwitterOAuth\TwitterOAuth;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use GuzzleHttp\Client;
use Museomix\Entity\Artefact;

$app = new Silex\Application();

// Uncomment those for debugging purpose.
//$app['debug'] = true;
//error_reporting(E_ALL);
//ini_set('display_errors','On');

$app->register(new Silex\Provider\TwigServiceProvider(), array(
  'twig.path' => __DIR__.'/../views',
));
$app->register(new DerAlex\Silex\YamlConfigServiceProvider(__DIR__ . '/../config/parameters.yml'));

$app['twig'] = $app->share($app->extend('twig', function($twig, $app) {
  $twig->addGlobal('google_map_api_key', $app['config']['google']['map_api_key']);
  $twig->addGlobal('twitter_consumer_username', $app['config']['twitter']['consumer_username']);

  return $twig;
}));

/** @var Artefact[] $artefacts */
$artefacts = array();

$artefact0 = new Artefact();
$artefact0->setId(0);
$artefact0->setName('esc white');
$artefact0->setColor('white');
$artefact0->setHashtag('escWhite');
$artefact0->setIcon('esc-white.png');
$artefact0->setImage('escwhite.jpg');
$artefacts[0] = $artefact0;

$artefact1 = new Artefact();
$artefact1->setId(1);
$artefact1->setName('esc yellow');
$artefact1->setColor('yellow');
$artefact1->setHashtag('escYellow');
$artefact1->setIcon('esc-yellow.png');
$artefact1->setImage('escyellow.jpg');
$artefacts[1] = $artefact1;

$artefact2 = new Artefact();
$artefact2->setId(2);
$artefact2->setName('esc pink');
$artefact2->setColor('pink');
$artefact2->setHashtag('escPink');
$artefact2->setIcon('esc-pink.png');
$artefact2->setImage('escpink.jpg');
$artefacts[2] = $artefact2;

$artefact3 = new Artefact();
$artefact3->setId(3);
$artefact3->setName('esc blue');
$artefact3->setColor('blue');
$artefact3->setHashtag('escBlue');
$artefact3->setIcon('esc-blue.png');
$artefact3->setImage('escblue.jpg');
$artefacts[3] = $artefact3;

/**
 * Converts IP address to coordinates.
 * Returns an object with lat and long attributes.
 *
 * @param string $ipAddress The IP address to geolocalize.
 * @return \stdClass
 */
function convertIpToCoordinates($ipAddress)
{
  $coordinates = new \stdClass();
  $coordinates->long = '';
  $coordinates->lat = '';

  // TODO: Handle freegeoip webservice errors.
  $client = new Client();
  $results = $client->request('GET', 'https://freegeoip.net/json/' . $ipAddress);

  $geocode = json_decode($results->getBody()->getContents());
  $coordinates->long = $geocode->longitude;
  $coordinates->lat = $geocode->latitude;

  return $coordinates;
}

/**
 * Returns an artefact according to
 * the first hashtag matched in the given message.
 *
 * @param string $twitterMessage
 * @return Artefact||null
 */
$hashtagToArtefact = function ($twitterMessage) use ($artefacts)
{
  preg_match_all("/(#\w+)/", $twitterMessage, $matches);
  foreach ($matches as $match) {
    if (in_array('#escWhite', $match)) {
      return $artefacts[0];
    } else if(in_array('#escYellow', $match)){
      return $artefacts[1];
    } else if(in_array('#escPink', $match)){
      return $artefacts[2];
    } else if(in_array('#escBlue', $match)){
      return $artefacts[3];
    }
  }

  return null;
};

/**
 * Shows all escapes.
 */
$app->get('/escapes', function () use ($app, $artefacts) {
  return $app['twig']->render('escape/index.html.twig', array());
});

/**
 * Returns a JSON with tweets that are geolocalized, related to the all escapes.
 * That feed will be consume by GoogleMap.
 */
$app->get('/escapes-map.json', function () use ($app, $artefacts, $hashtagToArtefact) {
  $mapSettings = array();

  $consumerKey = $app['config']['twitter']['consumer_key'];
  $consumerSecretKey = $app['config']['twitter']['consumer_secret_key'];
  $accessToken = $app['config']['twitter']['access_token'];
  $accessTokenSecret = $app['config']['twitter']['access_token_secret'];

  // TODO: Handle Twitter connection errors
  $connection = new TwitterOAuth($consumerKey, $consumerSecretKey, $accessToken, $accessTokenSecret);
  $connection->get('account/verify_credentials');

  // TODO: Handle Twitter tweets errors
  $tweets = $connection->get('statuses/user_timeline');

  // Adds tweets to the artefact.
  foreach ($tweets as $rawTweet) {

    // Avoid tweets that doesn't belong to an artefact.
    $artefact = $hashtagToArtefact($rawTweet->text);
    $tweet = new \StdClass();
    if ($artefact === null) {
      continue;
    }

    // Does not include tweets that doesn't have a location.
    if ($rawTweet->place === null) {
      continue;
    }

    $coordinates = array(
      'lng' => floatval($rawTweet->place->bounding_box->coordinates[0][0][0]),
      'lat' => floatval($rawTweet->place->bounding_box->coordinates[0][0][1])
    );

    $tweet->name = 'test';
    $tweet->message = $rawTweet->text;
    $tweet->coordinates = $coordinates;

    $artefact->addTweet($tweet);
  }

  // Center the map on the MFK in Bern.
  $mapSettings['centerCoordinates'] = array(
    'lat' => 46.941772,
    'lng' => 7.449993
  );
  $mapSettings['zoom'] = 13;

  $mapSettings['artefacts'] = array();
  foreach ($artefacts as $artefactb) {
    $mapSettings['artefacts'][] = array(
      'lineColor' => $artefactb->getColor(),
      'tweets' => $artefactb->getTweets()
    );
  }

  return new JsonResponse($mapSettings);
});

/**
 * Returns a JSON with tweets that are geolocalized, related to the given escape id.
 * That feed will be consume by GoogleMap.
 */
$app->get('/escape-map.json/{id}', function ($id) use ($app, $artefacts, $hashtagToArtefact) {
  $mapSettings = array();

  // Returns 404 if the given escape doesn't exist.
  if (!isset($artefacts[$id])) {
    return new JsonResponse('Sorry, the resource you are looking for could not be found.', 404);
  }

  $consumerKey = $app['config']['twitter']['consumer_key'];
  $consumerSecretKey = $app['config']['twitter']['consumer_secret_key'];
  $accessToken = $app['config']['twitter']['access_token'];
  $accessTokenSecret = $app['config']['twitter']['access_token_secret'];

  // TODO: Handle Twitter connection errors
  $connection = new TwitterOAuth($consumerKey, $consumerSecretKey, $accessToken, $accessTokenSecret);
  $connection->get('account/verify_credentials');

  // TODO: Handle Twitter tweets errors
  $tweets = $connection->get('statuses/user_timeline');

  foreach ($tweets as $rawTweet) {

    // Avoids tweets that doesn't belong to an escape id.
    $tweet = new \StdClass();
    if (
      $hashtagToArtefact($rawTweet->text) === null
      || $hashtagToArtefact($rawTweet->text)->getId() !== intval($id)
    ) {
      continue;
    }

    // Does not include tweets that doesn't have a location.
    if ($rawTweet->place === null) {
      continue;
    }

    $coordinates = array(
      'lng' => floatval($rawTweet->place->bounding_box->coordinates[0][0][0]),
      'lat' => floatval($rawTweet->place->bounding_box->coordinates[0][0][1])
    );

    $tweet->name = 'test';
    $tweet->message = $rawTweet->text;
    $tweet->coordinates = $coordinates;

    $artefacts[$id]->addTweet($tweet);
  }

  // Center the map on the MFK in Bern.
  $mapSettings['centerCoordinates'] = array(
    'lat' => 46.941772,
    'lng' => 7.449993
  );
  $mapSettings['zoom'] = 13;

  $mapSettings['artefacts'] = array();
  $mapSettings['artefacts'][] = array(
    'lineColor' => $artefacts[$id]->getColor(),
    'tweets' => $artefacts[$id]->getTweets()
  );

  return new JsonResponse($mapSettings);
});

/**
 * Posts a tweet for an escape.
 */
$app->post('/escape', function (Request $request) use ($app, $artefacts) {

  $tweetText = $request->request->get('tweet-body');
  $id = intval($request->get('escape-id'));

  // Detects the artefact or returns 404 if not found.
  if (!isset($artefacts[$id])) {
    $app->abort(404, 'Sorry, the resource you are looking for could not be found.');
  }

  $coordinates = convertIpToCoordinates($request->getClientIp());

  $consumerKey = $app['config']['twitter']['consumer_key'];
  $consumerSecretKey = $app['config']['twitter']['consumer_secret_key'];
  $accessToken = $app['config']['twitter']['access_token'];
  $accessTokenSecret = $app['config']['twitter']['access_token_secret'];

  // TODO: Handle Twitter connection errors
  $connection = new TwitterOAuth($consumerKey, $consumerSecretKey, $accessToken, $accessTokenSecret);
  $connection->get('account/verify_credentials');

  // Add main hashtag (MFK, ...) and the artefact hashtag.
  $tweetText .= ' @' . $app['config']['gres']['arobase_tag'];
  $tweetText .= ' #' . $artefacts[$id]->getHashtag();

  // TODO: Handle Twitter tweets errors
  $connection->post('statuses/update', array(
    'status' => $tweetText,
    'lat' => $coordinates->lat,
    'long' => $coordinates->long
  ));

  return $app->redirect('/escape/' . $id);
});

/**
 * Shows the escape.
 */
$app->get('/escape/{id}', function ($id) use ($app, $artefacts) {

  // Detects the artefact or returns 404 if not found.
  if (!isset($artefacts[$id])) {
    $app->abort(404, 'Sorry, the resource you are looking for could not be found.');
  }

  return $app['twig']->render('escape/show.html.twig', array('escape' => $artefacts[$id]));
});

$app->run();
