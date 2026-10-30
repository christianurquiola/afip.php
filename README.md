

<!-- PROJECT SHIELDS -->
[![Packagist][packagist-shield]](https://packagist.org/packages/afipsdk/afip.php)
[![Contributors][contributors-shield]](https://github.com/afipsdk/afip.php/graphs/contributors)
[![Closed issues][issues-shield]](https://github.com/afipsdk/afip.php/issues)
[![License][license-shield]](https://github.com/afipsdk/afip.php/blob/master/LICENSE)


<!-- PROJECT LOGO -->
<br />
<p align="center">
  <a href="https://github.com/afipsdk/afip.php">
    <img src="https://github.com/afipsdk/afipsdk.github.io/blob/master/images/logo-colored.png" alt="Logo" width="130" height="130">
  </a>

  <h3 align="center">Afip.php</h3>

  <p align="center">
    Librería para conectarse a los Web Services de AFIP
    <br />
    <a href="https://github.com/afipsdk/afip.php/wiki"><strong>Explorar documentación »</strong></a>
    <br />
    <br />
    <a href="https://github.com/afipsdk/afip.php/issues">Reportar un bug</a>
  </p>
</p>
<p align="center">
    <img src="https://github.com/afipsdk/afipsdk.github.io/blob/master/images/implementation.png" alt="Implementation">
</p>

<!-- TABLE OF CONTENTS -->
## Tabla de contenidos

* [Acerca del proyecto](#acerca-del-proyecto)
* [Guía de inicio](#guía-de-inicio)
  * [Instalación](#instalaci%C3%B3n)
  * [Como usarlo](#como-usarlo)
  * [Configuración SSL/TLS](#configuraci%C3%B3n-ssltls)
* [Web Services](#web-services)
  * [Factura electrónica](#factura-electr%C3%B3nica)
  * [Padrón alcance 4](#padr%C3%B3n-alcance-4)
  * [Padrón alcance 5](#padr%C3%B3n-alcance-5)
  * [Padrón alcance 10](#padr%C3%B3n-alcance-10)
  * [Padrón alcance 13](#padr%C3%B3n-alcance-13)
* [Migración](#migraci%C3%B3n)
* [Proyectos relacionados](#proyectos-relacionados)
* [Afip SDK PRO 🚀](#afip-sdk-pro-)
* [Contribuir](#contribuir)
* [Licencia](#licencia)
* [Contacto](#contacto)



<!-- ABOUT THE PROJECT -->
## Acerca del proyecto

Esta librería fue creada con la intención de ayudar a los programadores a usar los Web Services de AFIP sin romperse la cabeza ni perder tiempo tratando de entender la complicada documentación que AFIP provee. Ademas forma parte de [Afip SDK](https://afipsdk.github.io/).


<!-- START GUIDE -->
## Guía de inicio

### Instalación

#### Via Composer

```
composer require afipsdk/afip.php
```

#### Via Manual
1. Clonarlo con `git clone` o descargar el repositorio desde [aqui](https://github.com/AfipSDK/afip.php/archive/v0.6.0.zip "Descargar repositorio").
2. Copiar el contenido de la carpeta *res* a tu aplicación.

**Importante** 
* Remplazar `Afip_res/cert` por tu certificado provisto por AFIP y `Afip_res/key` por la clave generada. 
* Procuren que la carpeta `Afip_res` no sea accesible desde internet ya que allí se guardara toda la informacion para acceder a los web services, **ademas esta carpeta deberá tener permisos de escritura**.

Ir a http://www.afip.gob.ar/ws/documentacion/certificados.asp para obtener mas información de como generar la clave y certificado.

Si no pueden seguir la complicada documentación de AFIP para obtener el certificado pueden obtener [Afip SDK PRO](#afip-sdk-pro-) donde se explica cómo obtener los certificados fácilmente.

### Como usarlo

Si lo instalaste manualmente lo primero es incluir el SDK en tu aplicación
````php
include 'Afip.php';
````

Luego creamos una instancia de la clase Afip pasandole un Array como parámetro.
````php
$afip = new Afip(array('CUIT' => 20111111112));
````


Para más información acerca de los parámetros que se le puede pasar a la instancia new `Afip()` consulte sección [Primeros pasos](https://github.com/afipsdk/afip.php/wiki/Primeros-pasos#como-usarlo) de la documentación

Una vez realizado esto podemos comenzar a usar el SDK con los Web Services disponibles

### Configuración SSL/TLS

El servidor de factura electrónica de producción (`servicios1.afip.gov.ar`) prioriza cifrados DHE con una clave de 1024 bits, que OpenSSL 3 rechaza con `SECLEVEL=2` (por defecto en Ubuntu 22.04/24.04) con el error `dh key too small`, informado como `SOAP Fault: HTTP Could not connect to host`.

Por eso, **sin configuración adicional**, la factura electrónica (producción y homologación) negocia solo cifrados `ECDHE+AESGCM`, que tienen forward secrecy y son aceptados con `SECLEVEL=2`. No es necesario bajar el nivel de seguridad de OpenSSL.

* WSAA y los padrones no cambian: usan la configuración por defecto de PHP y OpenSSL.
* La verificación del certificado y del nombre del servidor (`verify_peer` y `verify_peer_name`) siempre queda activa y no puede desactivarse con esta opción.
* TLS 1.3 no se ve afectado (la lista de cifrados aplica solo hasta TLS 1.2).

Para personalizarlo se puede pasar la opción `ssl` con [opciones de contexto SSL de PHP](https://www.php.net/manual/es/context.ssl.php). Se aplica a todos los web services y su `ciphers` reemplaza al de la factura electrónica. Si `ssl` no es un array se ignora.
````php
// Otra lista de cifrados
$afip = new Afip(array('CUIT' => 20111111112, 'ssl' => array('ciphers' => 'ECDHE+AESGCM:ECDHE+CHACHA20')));

// Desactivar la política: lista de cifrados por defecto de PHP (comportamiento anterior)
$afip = new Afip(array('CUIT' => 20111111112, 'ssl' => array('ciphers' => NULL)));
````

#### Pruebas

````
php tests/unit.php                               # sin red
php tests/integration.php --network=testing      # homologación de ARCA y hosts públicos de prueba
````

`tests/integration.php` no se conecta a nada sin `--network`, y solo se conecta a producción con `--network=production`. Por defecto fuerza `SECLEVEL=2` en el proceso. Con `--seclevel=system` usa la configuración de OpenSSL del entorno; para simular un `SECLEVEL=2` global sin modificar el servidor se puede usar `OPENSSL_CONF` solo para ese proceso:
````
cat > /tmp/openssl-seclevel2.cnf <<'EOF'
openssl_conf = default_conf
[default_conf]
ssl_conf = ssl_sect
[ssl_sect]
system_default = system_default_sect
[system_default_sect]
MinProtocol = TLSv1.2
CipherString = DEFAULT:@SECLEVEL=2
EOF
OPENSSL_CONF=/tmp/openssl-seclevel2.cnf php tests/integration.php --network=testing --seclevel=system
````
Las credenciales de homologación (opcionales) se toman de variables de entorno y nunca se guardan en el repositorio, ver el encabezado de `tests/integration.php`. Las pruebas no generan comprobantes ni solicitan CAE.


<!-- WEB SERVICES -->
## Web Services

Si necesitas más información de cómo utilizar algún web service echa un vistazo a la [documentación completa de afip.php](https://github.com/afipsdk/afip.php/wiki)

**Además si necesitas usar otro web service que aún no está disponible aquí podes utilizar esta librería como base para que se te haga más fácil, pronto haremos un tutorial explicando paso a paso como hacerlo, pero por el momento te recomendamos comenzar haciendo una copia y modificando el código de [consulta al padrón alcance 5](https://github.com/afipsdk/afip.php/blob/master/src/Afip_res/Class/RegisterScopeFive.php)**

### Factura electrónica
Podes encontrar la documentación necesaria para utilizar la [facturación electrónica](https://github.com/afipsdk/afip.php/wiki/Facturaci%C3%B3n-Electr%C3%B3nica) 👈 aquí

### Padrón alcance 4
El Servicio Web de Consulta de Padrón denominado A4 ha quedado limitado para Organismos Públicos, si lo necesitas puedes leer la documentación de [consulta al padrón de AFIP alcance 4](https://github.com/afipsdk/afip.php/wiki/Consulta-al-padron-de-AFIP-alcance-4)

### Padrón alcance 5
Quienes usaban el padrón A4 pueden utilizar este padrón en modo de remplazo, si queres saber cómo echa un vistazo a la documentación de [consulta al padrón de AFIP alcance 5](https://github.com/afipsdk/afip.php/wiki/Consulta-al-padron-de-AFIP-alcance-5)

### Padrón alcance 10
Si tenes que utilizar este web service también está disponible dentro de la librería, su documentación se encuentra en [consulta al padrón de AFIP alcance 10](https://github.com/afipsdk/afip.php/wiki/Consulta-al-padron-de-AFIP-alcance-10)

### Padrón alcance 13
Si debes consultar por el CUIT de una persona física tendrás que utilizar este web service, su documentación se encuentra disponible en la wiki de [consulta al padrón de AFIP alcance 13](https://github.com/AfipSDK/afip.php/wiki/Consulta-al-padron-de-AFIP-alcance-13)


<!-- MIGRATION -->
### Migración
¿Necesitas migrar de versión de la librería?

Pueden encontrar el tutorial correspondiente aquí 👇
- [Migrar de v0.1 a v0.5](https://github.com/afipsdk/afip.php/wiki/Migrar-de-v0.1-a-v0.5)


<!-- RELATED PROJECTS-->
### Proyectos relacionados

#### Bundle para Symfony
Si necesitas utilizar los web services de Afip en _Symfony_ podes utilizar este [bundle](https://github.com/gonzakpo/afip)

<!-- AFIP SDK PRO -->
### Afip SDK PRO 🚀

¿Necesitas ayuda? Obtén Afip SDK PRO que incluye soporte personalizado por 3 meses (o más) donde te ayudaremos integrar los web services de Afip con tu aplicación, y una amplia documentación con ejemplos, tutoriales, implementación en Frameworks y mucho más. 


**[Saber más](https://afipsdk.github.io/pro.html)**


<!-- CONTRIBUTION -->
### Contribuir
Las contribuciones son las que hacen que la comunidad de código abierto sea un lugar increíble para aprender, inspirar y crear. Cualquier contribución que hagas es **muy apreciada**.

1. Haz un Fork
2. Crea el Branch de tu Feature (`git checkout -b feature/AmazingFeature`)
3. Agrega tus cambios (`git commit -m 'Add some AmazingFeature`)
4. Haz un Push al Branch (`git push origin feature/AmazingFeature`)
5. Abre un Pull Request


<!-- LICENCE -->
### Licencia
Distribuido bajo la licencia MIT. Vea `LICENSE` para más información.


<!-- CONTACT -->
### Contacto
Afip SDK - afipsdk@gmail.com

Link del proyecto: [https://github.com/afipsdk/afip.php](https://github.com/afipsdk/afip.php)


_Este software y sus desarrolladores no tienen ninguna relación con la AFIP._

<!-- MARKDOWN LINKS & IMAGES -->
[packagist-shield]: https://img.shields.io/packagist/dt/afipsdk/afip.php.svg??logo=php&?logoColor=white
[contributors-shield]: https://img.shields.io/github/contributors/afipsdk/afip.php.svg?color=orange
[issues-shield]: https://img.shields.io/github/issues-closed-raw/afipsdk/afip.php.svg?color=blueviolet
[license-shield]: https://img.shields.io/github/license/afipsdk/afip.php.svg?color=blue

