DROP TABLE public.user;

CREATE TABLE public.user
(
  username character varying(20) NOT NULL,
  pass character varying(32) NOT NULL,
  name character varying(50) NOT NULL,
  surname character varying(50) NOT NULL,
  CONSTRAINT user_pkey PRIMARY KEY (username)
)
WITH (
OIDS=FALSE
);

INSERT INTO public.user(username, pass, name, surname) values('gonzalo', md5('password'), 'Gonzalo', 'Ayuso');
INSERT INTO public.user(username, pass, name, surname) values('spiderman', md5('password'), 'Peter', 'Parker');