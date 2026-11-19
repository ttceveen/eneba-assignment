BEGIN;
LOCK TABLE games IN SHARE ROW EXCLUSIVE MODE;

CREATE TEMP TABLE expected_game_descriptions (
    id bigint PRIMARY KEY,
    name text NOT NULL,
    description text NOT NULL CHECK (btrim(description) <> '')
) ON COMMIT DROP;

INSERT INTO expected_game_descriptions (id, name, description) VALUES
(1, 'Cyberpunk 2077', 'Play as V, a mercenary trying to survive after a heist leaves a digital copy of Johnny Silverhand in their head. Take jobs across Night City and approach them with weapons, stealth, or hacking.'),
(2, 'The Witcher 3: Wild Hunt', 'Geralt of Rivia searches for Ciri as the Wild Hunt closes in on her. Track monsters, investigate local disputes, and make decisions that shape what happens to the people you meet.'),
(3, 'Grand Theft Auto V', 'Follow Michael, Franklin, and Trevor as their ambitions draw them into a series of heists in Los Santos and Blaine County. Switch between the three protagonists during the story and explore the city and surrounding countryside between missions.'),
(4, 'Red Dead Redemption 2', 'Arthur Morgan rides with the Van der Linde gang as lawmen close in and the American frontier changes. Follow the gang’s story while hunting, riding, and meeting strangers across towns and wilderness.'),
(5, 'Hogwarts Legacy', 'Enter Hogwarts as a fifth-year student in the late nineteenth century with an unusual connection to ancient magic. Learn spells, explore the castle and nearby villages, and investigate a conflict growing beyond the school grounds.'),
(6, 'Elden Ring', 'Travel through the Lands Between as a Tarnished seeking the scattered Great Runes. Explore ruins, caves, and castles, then develop a fighting style around the weapons, spells, and skills you find.'),
(7, 'Baldur''s Gate 3', 'Gather a party of adventurers after a mind flayer parasite takes root in your character. Dialogue choices and dice rolls shape the story, while turn-based battles reward positioning and creative use of spells and terrain.'),
(8, 'Minecraft', 'Explore a world made of blocks, gather materials, and build structures of your own design. Survival asks you to manage resources and fend off creatures, while Creative mode gives you room to build freely.'),
(9, 'Resident Evil 4', 'Leon S. Kennedy searches for the US president’s missing daughter in a remote Spanish village. Fight through hostile settlements, solve environmental puzzles, and manage ammunition as the rescue leads into a wider conspiracy.'),
(10, 'God of War', 'Kratos and his son Atreus cross a world inspired by Norse mythology to fulfill a family promise. Fight with the Leviathan Axe, use Atreus’s help in combat, and explore realms linked to their journey.'),
(11, 'Starfield', 'Join Constellation, a group investigating mysterious artifacts across the Settled Systems. Travel between planets aboard your ship, meet competing factions, and explore settlements and wilderness on foot.'),
(12, 'Fallout 4', 'Emerging from Vault 111, the Sole Survivor searches for a missing child in the ruins around Boston. Explore the Commonwealth, deal with rival factions, and use scavenged materials to modify equipment and build settlements.'),
(13, 'Fallout: New Vegas', 'A courier left for dead tracks down a stolen package in the Mojave Wasteland. Choose how to deal with rival powers around New Vegas through dialogue, reputation, and combat.'),
(14, 'The Elder Scrolls V: Skyrim', 'Explore Skyrim as the Dragonborn while dragons return and a civil war divides the province. Learn powerful shouts, delve into Nordic ruins, and develop skills through the combat and magic you use.'),
(15, 'Assassin''s Creed Valhalla', 'Lead Eivor’s clan from Norway to ninth-century England and establish a new settlement. Raid monasteries, form alliances across rival kingdoms, and follow Eivor’s involvement in the conflict between the Hidden Ones and the Order of the Ancients.'),
(16, 'Assassin''s Creed Odyssey', 'Play as Kassandra or Alexios, a mercenary searching for answers about their family during the Peloponnesian War. Sail between Greek islands, take sides in local conflicts, and choose how to approach quests and battles.'),
(17, 'Assassin''s Creed Origins', 'Follow Bayek of Siwa through Ptolemaic Egypt as a personal loss draws him into a struggle against a secret order. Explore cities, deserts, and tombs, and use stealth, archery, or close combat to reach your targets.'),
(18, 'Far Cry 6', 'Join a guerrilla movement as Dani Rojas on the fictional Caribbean island of Yara, ruled by Antón Castillo. Scout military outposts and use improvised weapons to help resistance groups across the island.'),
(19, 'Far Cry 5', 'A junior deputy arrives in rural Montana to arrest the leader of the Project at Eden’s Gate cult. Work with local resistance members to reclaim parts of Hope County using firearms, vehicles, and companions.'),
(20, 'Watch Dogs: Legion', 'Rebuild DedSec in a near-future London under private military control. Recruit residents with different abilities and use hacking, stealth, or direct confrontation to carry out missions.'),
(21, 'Dying Light 2 Stay Human', 'Aiden Caldwell searches for his sister in a city where survivors compete for resources. Traverse rooftops with parkour, fight with melee weapons, and decide which groups to help as infected enemies grow more dangerous at night.'),
(22, 'Dead Space', 'Engineer Isaac Clarke boards the USG Ishimura after the mining ship sends a distress signal. Repair failing systems, navigate zero-gravity areas, and use industrial tools to dismember the creatures roaming its corridors.'),
(23, 'DOOM Eternal', 'Fight as the Doom Slayer after demonic armies invade Earth. Switch weapons and use close-range finishers, fire, and the chainsaw to replenish health, armor, and ammunition during fast arena battles.'),
(24, 'Forza Horizon 5', 'Drive through a version of Mexico that spans cities, desert, jungle, coast, and a volcano. Enter road and off-road events, collect cars, and tune them for different surfaces and race types.'),
(25, 'Forza Horizon 4', 'Take part in the Horizon Festival across a version of Great Britain. Race on roads and dirt tracks while changing seasons alter conditions, from wet autumn roads to snow and ice in winter.'),
(26, 'Horizon Zero Dawn', 'Aloy investigates her origins in a world where animal-like machines roam the ruins of an older civilization. Study their components and use traps, arrows, and elemental weapons to take them apart.'),
(27, 'Horizon Forbidden West', 'Aloy travels west in search of a way to halt a spreading blight. Explore settlements and ancient ruins above and below water, and target vulnerable machine components in combat.'),
(28, 'Marvel''s Spider-Man Remastered', 'Play Peter Parker as an experienced Spider-Man trying to balance his private life with protecting New York. Swing across Manhattan and combine gadgets, web attacks, and acrobatics as a new threat emerges.'),
(29, 'Marvel''s Spider-Man: Miles Morales', 'Miles Morales takes responsibility for protecting his neighborhood when a conflict between an energy company and the Underground threatens Harlem. Swing across wintertime New York and use camouflage and bioelectric attacks in combat.'),
(30, 'The Last of Us Part I', 'Joel escorts Ellie across a United States transformed by a fungal outbreak. Stealth, scavenging, and limited supplies shape encounters with infected creatures and hostile survivors along the way.'),
(31, 'Death Stranding', 'Courier Sam Porter Bridges delivers cargo across a fractured America and works to reconnect isolated settlements. Choose routes and balance your load while crossing rough terrain and avoiding supernatural threats.'),
(32, 'Control Ultimate Edition', 'Jesse Faden enters the Federal Bureau of Control’s shifting headquarters while looking for her missing brother. Explore the building and use a transforming weapon and telekinetic powers to fight the force that has taken it over.'),
(33, 'Alan Wake 2', 'FBI agent Saga Anderson investigates ritual murders in the Pacific Northwest while writer Alan Wake struggles to escape the Dark Place. Piece together clues across both stories and use light to weaken enemies before attacking.'),
(34, 'Metro Exodus', 'Artyom leaves the Moscow metro aboard the Aurora with a group searching for a place to live. Scavenge for supplies, maintain your weapons, and use stealth or combat in stations and open regions along the railway.'),
(35, 'Kingdom Come: Deliverance', 'Henry, a blacksmith’s son, is drawn into conflict after his village is attacked in medieval Bohemia. Learn swordsmanship and deal with quests through conversation, stealth, or combat.'),
(36, 'Kingdom Come: Deliverance II', 'Henry accompanies Sir Hans Capon on a diplomatic mission that draws them deeper into the struggle for control of Bohemia. Explore villages and the city of Kuttenberg, and use conversation, stealth, or practiced weapon skills to get through difficult situations.'),
(37, 'Diablo IV', 'Journey through Sanctuary as Lilith returns and draws its inhabitants into a new conflict. Build a character around class skills, clear dungeons, and use equipment drops to refine how you fight.'),
(38, 'Monster Hunter: World', 'Join the Research Commission in the New World and track large monsters through interconnected habitats. Learn their behavior and craft weapons and armor from materials gathered during hunts.'),
(39, 'Monster Hunter Wilds', 'Travel with the expedition into the Forbidden Lands to investigate a boy’s account of a monster that attacked his village. Hunt creatures through changing weather and use a Seikret mount to traverse the region and carry a second weapon.'),
(40, 'Sekiro: Shadows Die Twice', 'A shinobi known as Wolf fights to rescue his young lord in a fictionalized Sengoku-era Japan. Break enemy posture with deflections and attacks, and use tools fitted to your prosthetic arm to deal with specific threats.');

DO $$
BEGIN
    IF (SELECT count(*) FROM expected_game_descriptions) <> 40 OR EXISTS (
        SELECT 1
        FROM games
        FULL JOIN expected_game_descriptions USING (id)
        WHERE games.name IS DISTINCT FROM expected_game_descriptions.name
           OR games.is_active IS DISTINCT FROM true
    ) THEN
        RAISE EXCEPTION 'Game IDs, names or active status differ from the expected catalog';
    END IF;
END;
$$;

UPDATE games
SET description = expected.description
FROM expected_game_descriptions AS expected
WHERE games.id = expected.id
  AND (games.description IS NULL OR games.description ~ '^[[:space:]]*$');

COMMIT;
