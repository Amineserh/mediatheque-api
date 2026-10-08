<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

class AlbumRepository extends BaseRepository
{
    protected string $table      = 'albums';
    protected string $primaryKey = 'idAlbums';
    protected array  $columns    = ['Titre', 'Artist_idArtist'];

    public function findByArtist(int $idArtist): array
    {
        $sth = $this->db->prepare("SELECT * FROM `albums` WHERE `Artist_idArtist` = :id");
        $sth->execute(['id' => $idArtist]);

        return $sth->fetchAll(PDO::FETCH_ASSOC);
    }
}
