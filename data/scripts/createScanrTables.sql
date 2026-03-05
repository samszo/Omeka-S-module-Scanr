--
-- Structure de la table `scanr_domain`
--

CREATE TABLE `scanr_domain` (
  `id` int(11) NOT NULL,
  `label` varchar(100) NOT NULL,
  `code` varchar(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Structure de la table `scanr_structure`
--

CREATE TABLE `scanr_structure` (
  `id` int(11) NOT NULL,
  `label` varchar(255) NOT NULL,
  `ref` varchar(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Structure de la table `scarn_auteur`
--

CREATE TABLE `scarn_auteur` (
  `id` int(11) NOT NULL,
  `firstname` varchar(250) NOT NULL,
  `lastname` varchar(250) NOT NULL,
  `ref` varchar(250) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Index pour les tables déchargées
--

--
-- Index pour la table `scanr_domain`
--
ALTER TABLE `scanr_domain`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `scanr_domain_code` (`code`),
  ADD KEY `scanr_domain_label` (`label`) USING BTREE;

--
-- Index pour la table `scanr_structure`
--
ALTER TABLE `scanr_structure`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `scanr_structure_ref` (`ref`),
  ADD KEY `scanr_structure_label` (`label`);

--
-- Index pour la table `scarn_auteur`
--
ALTER TABLE `scarn_auteur`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `scanr_auteur_ref` (`id`),
  ADD KEY `scanr_auteur_name` (`firstname`,`lastname`);

--
-- AUTO_INCREMENT pour la table `scanr_structure`
--
ALTER TABLE `scanr_structure`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT pour la table `scarn_auteur`
--
ALTER TABLE `scarn_auteur`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT pour la table `scarn_auteur`
--
ALTER TABLE `scanr_domain`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;