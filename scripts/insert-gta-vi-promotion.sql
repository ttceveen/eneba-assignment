BEGIN;
LOCK TABLE promotions IN SHARE ROW EXCLUSIVE MODE;

INSERT INTO promotions (title, image_url, link_url)
SELECT 'Grand Theft Auto VI', '/images/banners/gta-vi-desktop.jpg', '#'
WHERE NOT EXISTS (SELECT 1 FROM promotions);

COMMIT;
