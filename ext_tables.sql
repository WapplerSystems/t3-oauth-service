CREATE TABLE tx_oauthsvc_client
(
	uid               int(11)                  NOT NULL auto_increment,
	pid               int(11)      DEFAULT '0' NOT NULL,

	identifier        varchar(191) DEFAULT ''  NOT NULL,
	title             varchar(255) DEFAULT ''  NOT NULL,
	provider_type     varchar(80)  DEFAULT ''  NOT NULL,

	client_id         text,
	client_secret_enc text,

	callback_route    varchar(191) DEFAULT ''  NOT NULL,
	scopes            text,

	is_active         tinyint(1)   DEFAULT '1' NOT NULL,
	notify_email      varchar(255) DEFAULT ''  NOT NULL,

	created_at        int(11)      DEFAULT '0' NOT NULL,
	updated_at        int(11)      DEFAULT '0' NOT NULL,

	PRIMARY KEY (uid),
	UNIQUE KEY uniq_identifier (identifier),
	KEY parent (pid)
);

CREATE TABLE tx_oauthsvc_connection
(
	uid                int(11)                             NOT NULL auto_increment,
	pid                int(11)      DEFAULT '0'            NOT NULL,

	client_uid         int(11)      DEFAULT '0'            NOT NULL,
	label              varchar(255) DEFAULT ''             NOT NULL,

	status             varchar(30)  DEFAULT 'disconnected' NOT NULL,

	state_hash         varchar(255) DEFAULT ''             NOT NULL,
	state_created_at   int(11)      DEFAULT '0'            NOT NULL,

	access_token_enc   mediumtext,
	refresh_token_enc  mediumtext,
	token_type         varchar(30)  DEFAULT ''             NOT NULL,
	expires_at         int(11)      DEFAULT '0'            NOT NULL,

	last_refresh_at    int(11)      DEFAULT '0'            NOT NULL,
	last_check_at      int(11)      DEFAULT '0'            NOT NULL,

	last_error_code    varchar(80)  DEFAULT ''             NOT NULL,
	last_error_message text,

	remote_subject     varchar(255) DEFAULT ''             NOT NULL,
	meta               mediumtext,

	created_at         int(11)      DEFAULT '0'            NOT NULL,
	updated_at         int(11)      DEFAULT '0'            NOT NULL,

	PRIMARY KEY (uid),
	KEY client (client_uid),
	KEY parent (pid)
);
