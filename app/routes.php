<?php

declare(strict_types=1);

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use App\Middleware\JwtHelper;
use App\Middleware\JwtMiddleware;
use App\Repository\ArtistRepository;
use App\Repository\AlbumRepository;
use Slim\Interfaces\RouteCollectorProxyInterface as Group;

/**
 * Base "music" :
 *   artists (idArtist, Name, Annee, Description)
 *   albums  (idAlbums, Titre, Artist_idArtist)   -> FK vers artists.idArtist
 *   ratings (idRatings, Grade, Albums_idAlbums)  -> FK vers albums.idAlbums
 */

return function (App $app) {

    /* ==================================================================
 | AUTHENTIFICATION JWT
 * =================================================================*/

    // --- Obtenir un jeton ------------------------------------------------
    $app->post('/login', function (Request $request, Response $response) {
        $params   = (array) $request->getParsedBody();
        $username = $params['username'] ?? '';
        $password = $params['password'] ?? '';

        if ($username === 'SaintMichel' && $password === 'ITcampus') {

            $userData = ['id' => 1, 'username' => $username];
            $token    = JwtHelper::generateToken($userData);

            $response->getBody()->write(json_encode(['token' => $token]));
            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withStatus(200);
        }

        $response->getBody()->write(json_encode(['error' => 'Invalid credentials']));
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus(401);
    });


    // --- Route protegee ---------------------------------------------------
    $app->get('/protected', function (Request $request, Response $response) {
        $user = $request->getAttribute('user');

        $response->getBody()->write(json_encode([
            'message' => 'Hello, ' . $user->username,
        ]));
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus(200);

    })->add(new JwtMiddleware());

    /* ==================================================================
     | Routes de test
     * =================================================================*/

    $app->get('/', function (Request $request, Response $response) {
        $response->getBody()->write('Hello world!');
        return $response;
    });

    $app->get('/ping', function (Request $request, Response $response) {
        $response->getBody()->write(json_encode(['message' => 'pong']));
        return $response->withHeader('Content-Type', 'application/json');
    });


    /* ==================================================================
     | ARTISTS
     * =================================================================*/

    // --- Liste de tous les artistes ------------------------------------
    $app->get('/artists', function (Request $request, Response $response) {
        try {
            $db  = $this->get(PDO::class);
            $sth = $db->prepare("SELECT * FROM `artists`");
            $sth->execute();

            $response->getBody()->write(json_encode($sth->fetchAll(PDO::FETCH_ASSOC)));
            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withStatus(200);
        } catch (PDOException $e) {
            $response->getBody()->write(json_encode(['message' => 'Erreur serveur']));
            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withStatus(500);
        }
    });


    // --- Un artiste par son identifiant --------------------------------
    $app->get('/artists/{id}', function (Request $request, Response $response, array $args) {
        try {
            $db  = $this->get(PDO::class);
            $sth = $db->prepare("SELECT * FROM `artists` WHERE `idArtist` = :id");
            $sth->bindValue(':id', $args['id']);
            $sth->execute();

            $data = $sth->fetch(PDO::FETCH_ASSOC);

            if (!$data) {
                $response->getBody()->write(json_encode(['error' => 'Artiste introuvable']));
                return $response
                    ->withHeader('Content-Type', 'application/json')
                    ->withStatus(404);
            }

            $response->getBody()->write(json_encode($data));
            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withStatus(200);
        } catch (PDOException $e) {
            $response->getBody()->write(json_encode(['message' => 'Erreur serveur']));
            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withStatus(500);
        }
    });


    // --- Les albums d'un artiste ---------------------------------------
    $app->get('/artists/{id}/albums', function (Request $request, Response $response, array $args) {
        try {
            $db  = $this->get(PDO::class);
            $sth = $db->prepare("SELECT * FROM `albums` WHERE `Artist_idArtist` = :id");
            $sth->bindValue(':id', $args['id']);
            $sth->execute();

            $response->getBody()->write(json_encode($sth->fetchAll(PDO::FETCH_ASSOC)));
            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withStatus(200);
        } catch (PDOException $e) {
            $response->getBody()->write(json_encode(['message' => 'Erreur serveur']));
            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withStatus(500);
        }
    });


    // --- Creer un artiste ----------------------------------------------
    $app->post('/artists', function (Request $request, Response $response) {
        $input = $request->getParsedBody();

        if (empty($input['Name'])) {
            $response->getBody()->write(json_encode(['error' => 'Le champ Name est obligatoire']));
            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withStatus(400);
        }

        try {
            $db  = $this->get(PDO::class);
            $sth = $db->prepare(
                "INSERT INTO `artists` (`Name`, `Annee`, `Description`)
                 VALUES (:name, :annee, :description)"
            );
            $sth->bindValue(':name', $input['Name']);
            $sth->bindValue(':annee', $input['Annee']       ?? null);
            $sth->bindValue(':description', $input['Description'] ?? null);
            $sth->execute();

            $insertedId = $db->lastInsertId();

            $response->getBody()->write(json_encode([
                'message'  => 'Artiste cree avec succes',
                'idArtist' => $insertedId,
            ]));
            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withHeader('Location', '/artists/' . $insertedId)
                ->withStatus(201);
        } catch (PDOException $e) {
            $response->getBody()->write(json_encode(['message' => 'Erreur serveur']));
            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withStatus(500);
        }
    });


    // --- Modifier un artiste --------------------------------------------
    $app->put('/artists/{id}', function (Request $request, Response $response, array $args) {
        $input = $request->getParsedBody();

        try {
            $db  = $this->get(PDO::class);
            $sth = $db->prepare(
                "UPDATE `artists`
                 SET `Name` = :name, `Annee` = :annee, `Description` = :description
                 WHERE `idArtist` = :id"
            );
            $sth->bindValue(':id', $args['id']);
            $sth->bindValue(':name', $input['Name']        ?? null);
            $sth->bindValue(':annee', $input['Annee']       ?? null);
            $sth->bindValue(':description', $input['Description'] ?? null);
            $sth->execute();

            $response->getBody()->write(json_encode([
                'message' => 'Artiste mis a jour',
                'lignes'  => $sth->rowCount(),
            ]));
            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withStatus(200);
        } catch (PDOException $e) {
            $response->getBody()->write(json_encode(['message' => 'Erreur serveur']));
            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withStatus(500);
        }
    });


    // --- Supprimer un artiste -------------------------------------------
    $app->delete('/artists/{id}', function (Request $request, Response $response, array $args) {
        try {
            $db  = $this->get(PDO::class);
            $sth = $db->prepare("DELETE FROM `artists` WHERE `idArtist` = :id");
            $sth->bindValue(':id', $args['id']);
            $sth->execute();

            if ($sth->rowCount() === 0) {
                $response->getBody()->write(json_encode(['error' => 'Artiste introuvable']));
                return $response
                    ->withHeader('Content-Type', 'application/json')
                    ->withStatus(404);
            }

            return $response->withStatus(204);
        } catch (PDOException $e) {
            // Erreur 1451 : l'artiste possede encore des albums (cle etrangere)
            $response->getBody()->write(json_encode([
                'error' => 'Suppression impossible : cet artiste possede encore des albums',
            ]));
            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withStatus(409);
        }
    });


    /* ==================================================================
     | ALBUMS
     * =================================================================*/

    // --- Liste des albums, avec le nom de l'artiste ----------------------
    $app->get('/albums', function (Request $request, Response $response) {
        try {
            $db  = $this->get(PDO::class);
            $sth = $db->prepare(
                "SELECT al.`idAlbums`, al.`Titre`, al.`Artist_idArtist`, ar.`Name` AS `Artiste`
                 FROM `albums` al
                 INNER JOIN `artists` ar ON ar.`idArtist` = al.`Artist_idArtist`"
            );
            $sth->execute();

            $response->getBody()->write(json_encode($sth->fetchAll(PDO::FETCH_ASSOC)));
            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withStatus(200);
        } catch (PDOException $e) {
            $response->getBody()->write(json_encode(['message' => 'Erreur serveur']));
            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withStatus(500);
        }
    });


    // --- Un album par son identifiant ------------------------------------
    $app->get('/albums/{id}', function (Request $request, Response $response, array $args) {
        try {
            $db  = $this->get(PDO::class);
            $sth = $db->prepare("SELECT * FROM `albums` WHERE `idAlbums` = :id");
            $sth->bindValue(':id', $args['id']);
            $sth->execute();

            $data = $sth->fetch(PDO::FETCH_ASSOC);

            if (!$data) {
                $response->getBody()->write(json_encode(['error' => 'Album introuvable']));
                return $response
                    ->withHeader('Content-Type', 'application/json')
                    ->withStatus(404);
            }

            $response->getBody()->write(json_encode($data));
            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withStatus(200);
        } catch (PDOException $e) {
            $response->getBody()->write(json_encode(['message' => 'Erreur serveur']));
            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withStatus(500);
        }
    });


    // --- Creer un album ---------------------------------------------------
    $app->post('/albums', function (Request $request, Response $response) {
        $input = $request->getParsedBody();

        if (empty($input['Titre']) || empty($input['Artist_idArtist'])) {
            $response->getBody()->write(json_encode([
                'error' => 'Les champs Titre et Artist_idArtist sont obligatoires',
            ]));
            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withStatus(400);
        }

        try {
            $db  = $this->get(PDO::class);
            $sth = $db->prepare(
                "INSERT INTO `albums` (`Titre`, `Artist_idArtist`)
                 VALUES (:titre, :idartist)"
            );
            $sth->bindValue(':titre', $input['Titre']);
            $sth->bindValue(':idartist', $input['Artist_idArtist']);
            $sth->execute();

            $insertedId = $db->lastInsertId();

            $response->getBody()->write(json_encode([
                'message'  => 'Album cree avec succes',
                'idAlbums' => $insertedId,
            ]));
            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withHeader('Location', '/albums/' . $insertedId)
                ->withStatus(201);
        } catch (PDOException $e) {
            // Erreur 1452 : l'artiste reference n'existe pas
            $response->getBody()->write(json_encode([
                'error' => 'Creation impossible : verifiez que l artiste reference existe',
            ]));
            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withStatus(400);
        }
    });


    // --- Modifier un album -------------------------------------------------
    $app->put('/albums/{id}', function (Request $request, Response $response, array $args) {
        $input = $request->getParsedBody();

        try {
            $db  = $this->get(PDO::class);
            $sth = $db->prepare(
                "UPDATE `albums`
                 SET `Titre` = :titre, `Artist_idArtist` = :idartist
                 WHERE `idAlbums` = :id"
            );
            $sth->bindValue(':id', $args['id']);
            $sth->bindValue(':titre', $input['Titre']           ?? null);
            $sth->bindValue(':idartist', $input['Artist_idArtist'] ?? null);
            $sth->execute();

            $response->getBody()->write(json_encode([
                'message' => 'Album mis a jour',
                'lignes'  => $sth->rowCount(),
            ]));
            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withStatus(200);
        } catch (PDOException $e) {
            $response->getBody()->write(json_encode(['message' => 'Erreur serveur']));
            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withStatus(500);
        }
    });


    // --- Supprimer un album -------------------------------------------------
    $app->delete('/albums/{id}', function (Request $request, Response $response, array $args) {
        try {
            $db  = $this->get(PDO::class);
            $sth = $db->prepare("DELETE FROM `albums` WHERE `idAlbums` = :id");
            $sth->bindValue(':id', $args['id']);
            $sth->execute();

            if ($sth->rowCount() === 0) {
                $response->getBody()->write(json_encode(['error' => 'Album introuvable']));
                return $response
                    ->withHeader('Content-Type', 'application/json')
                    ->withStatus(404);
            }

            return $response->withStatus(204);
        } catch (PDOException $e) {
            $response->getBody()->write(json_encode([
                'error' => 'Suppression impossible : cet album possede encore des notes',
            ]));
            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withStatus(409);
        }
    });


    /* ==================================================================
     | RATINGS
     * =================================================================*/

    // --- Les notes d'un album ------------------------------------------------
    $app->get('/albums/{id}/ratings', function (Request $request, Response $response, array $args) {
        try {
            $db  = $this->get(PDO::class);
            $sth = $db->prepare("SELECT * FROM `ratings` WHERE `Albums_idAlbums` = :id");
            $sth->bindValue(':id', $args['id']);
            $sth->execute();

            $response->getBody()->write(json_encode($sth->fetchAll(PDO::FETCH_ASSOC)));
            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withStatus(200);
        } catch (PDOException $e) {
            $response->getBody()->write(json_encode(['message' => 'Erreur serveur']));
            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withStatus(500);
        }
    });


    // --- Ajouter une note a un album ------------------------------------------
    $app->post('/ratings', function (Request $request, Response $response) {
        $input = $request->getParsedBody();

        if (empty($input['Grade']) || empty($input['Albums_idAlbums'])) {
            $response->getBody()->write(json_encode([
                'error' => 'Les champs Grade et Albums_idAlbums sont obligatoires',
            ]));
            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withStatus(400);
        }

        try {
            $db  = $this->get(PDO::class);
            $sth = $db->prepare(
                "INSERT INTO `ratings` (`Grade`, `Albums_idAlbums`)
                 VALUES (:grade, :idalbums)"
            );
            $sth->bindValue(':grade', $input['Grade']);
            $sth->bindValue(':idalbums', $input['Albums_idAlbums']);
            $sth->execute();

            $response->getBody()->write(json_encode([
                'message'   => 'Note ajoutee avec succes',
                'idRatings' => $db->lastInsertId(),
            ]));
            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withStatus(201);
        } catch (PDOException $e) {
            $response->getBody()->write(json_encode([
                'error' => 'Creation impossible : verifiez que l album reference existe',
            ]));
            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withStatus(400);
        }
    });

/* ==================================================================
 | API REPOSITORY — routes protegees par JWT
 * =================================================================*/

$app->group('/api', function (Group $group) {

    // --- Artistes -------------------------------------------------------
    $group->get('/artists', function (Request $request, Response $response) {
        $repo = $this->get(ArtistRepository::class);
        $response->getBody()->write(json_encode($repo->findAll()));
        return $response->withHeader('Content-Type', 'application/json');
    });

    $group->get('/artists/year/{annee}', function (Request $request, Response $response, array $args) {
        $artists = $this->get(ArtistRepository::class)->findByYear((int) $args['annee']);
        $response->getBody()->write(json_encode($artists));
        return $response->withHeader('Content-Type', 'application/json');
    });

    $group->get('/artists/{id}', function (Request $request, Response $response, array $args) {
        $artist = $this->get(ArtistRepository::class)->findById((int) $args['id']);
        $response->getBody()->write(json_encode($artist ?? ['error' => 'Artiste introuvable']));
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus($artist ? 200 : 404);
    });

    $group->post('/artists', function (Request $request, Response $response) {
        $data = (array) $request->getParsedBody();

        if (empty($data['Name'])) {
            $response->getBody()->write(json_encode(['error' => 'Le champ Name est obligatoire']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        $id = $this->get(ArtistRepository::class)->insert($data);
        $response->getBody()->write(json_encode(['idArtist' => $id]));
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Location', '/api/artists/' . $id)
            ->withStatus(201);
    });

    $group->put('/artists/{id}', function (Request $request, Response $response, array $args) {
        $repo = $this->get(ArtistRepository::class);
        $id   = (int) $args['id'];

        if (!$repo->findById($id)) {
            $response->getBody()->write(json_encode(['error' => 'Artiste introuvable']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
        }

        $repo->update($id, (array) $request->getParsedBody());
        $response->getBody()->write(json_encode($repo->findById($id)));
        return $response->withHeader('Content-Type', 'application/json');
    });

    $group->delete('/artists/{id}', function (Request $request, Response $response, array $args) {
        try {
            $deleted = $this->get(ArtistRepository::class)->delete((int) $args['id']);
            return $response->withStatus($deleted ? 204 : 404);
        } catch (PDOException $e) {
            $response->getBody()->write(json_encode(['error' => 'Cet artiste possede encore des albums']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(409);
        }
    });

    // --- Albums ---------------------------------------------------------
    $group->get('/albums', function (Request $request, Response $response) {
        $response->getBody()->write(json_encode($this->get(AlbumRepository::class)->findAll()));
        return $response->withHeader('Content-Type', 'application/json');
    });

    $group->get('/artists/{id}/albums', function (Request $request, Response $response, array $args) {
        $albums = $this->get(AlbumRepository::class)->findByArtist((int) $args['id']);
        $response->getBody()->write(json_encode($albums));
        return $response->withHeader('Content-Type', 'application/json');
    });

})->add(new JwtMiddleware());
};